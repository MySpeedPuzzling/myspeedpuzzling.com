import Foundation
import WebKit
import AVFoundation
import Vision

/// JavaScript bridge for native barcode scanning
/// Receives messages from web JavaScript and triggers native scanner
class BarcodeScannerBridge: NSObject, WKScriptMessageHandler {
    weak var webView: WKWebView?

    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        guard message.name == "scanner",
              let body = message.body as? [String: Any],
              let action = body["action"] as? String else {
            return
        }

        switch action {
        case "open":
            openScanner()
        default:
            break
        }
    }

    private func openScanner() {
        // Request camera permission if needed
        AVCaptureDevice.requestAccess(for: .video) { [weak self] granted in
            DispatchQueue.main.async {
                if granted {
                    self?.presentScanner()
                } else {
                    self?.sendScanCancelled()
                }
            }
        }
    }

    private func presentScanner() {
        // Present the native scanner view controller
        guard let windowScene = UIApplication.shared.connectedScenes.first as? UIWindowScene,
              let rootViewController = windowScene.windows.first?.rootViewController else {
            return
        }

        let scannerVC = BarcodeScannerViewController()
        scannerVC.delegate = self
        scannerVC.modalPresentationStyle = .fullScreen
        rootViewController.present(scannerVC, animated: true)
    }

    private func sendScanResult(_ code: String) {
        let escapedCode = code.replacingOccurrences(of: "'", with: "\\'")
        let js = "window.onNativeScanResult('\(escapedCode)')"
        webView?.evaluateJavaScript(js)
    }

    private func sendScanCancelled() {
        let js = "window.onNativeScanCancelled()"
        webView?.evaluateJavaScript(js)
    }
}

extension BarcodeScannerBridge: BarcodeScannerViewControllerDelegate {
    func barcodeScannerDidScan(_ code: String) {
        sendScanResult(code)
    }

    func barcodeScannerDidCancel() {
        sendScanCancelled()
    }
}

// MARK: - Scanner View Controller

protocol BarcodeScannerViewControllerDelegate: AnyObject {
    func barcodeScannerDidScan(_ code: String)
    func barcodeScannerDidCancel()
}

class BarcodeScannerViewController: UIViewController {
    weak var delegate: BarcodeScannerViewControllerDelegate?

    private var captureSession: AVCaptureSession?
    private var previewLayer: AVCaptureVideoPreviewLayer?

    override func viewDidLoad() {
        super.viewDidLoad()
        view.backgroundColor = .black
        setupCamera()
        setupUI()
    }

    override func viewDidLayoutSubviews() {
        super.viewDidLayoutSubviews()
        previewLayer?.frame = view.bounds
    }

    override func viewWillAppear(_ animated: Bool) {
        super.viewWillAppear(animated)
        startScanning()
    }

    override func viewWillDisappear(_ animated: Bool) {
        super.viewWillDisappear(animated)
        stopScanning()
    }

    private func setupCamera() {
        captureSession = AVCaptureSession()

        guard let videoCaptureDevice = AVCaptureDevice.default(for: .video),
              let videoInput = try? AVCaptureDeviceInput(device: videoCaptureDevice),
              let captureSession = captureSession,
              captureSession.canAddInput(videoInput) else {
            return
        }

        captureSession.addInput(videoInput)

        let metadataOutput = AVCaptureMetadataOutput()
        if captureSession.canAddOutput(metadataOutput) {
            captureSession.addOutput(metadataOutput)
            metadataOutput.setMetadataObjectsDelegate(self, queue: DispatchQueue.main)
            metadataOutput.metadataObjectTypes = [.ean8, .ean13]
        }

        previewLayer = AVCaptureVideoPreviewLayer(session: captureSession)
        previewLayer?.videoGravity = .resizeAspectFill
        if let previewLayer = previewLayer {
            view.layer.addSublayer(previewLayer)
        }
    }

    private func setupUI() {
        // Cancel button
        let cancelButton = UIButton(type: .system)
        cancelButton.setTitle("Cancel", for: .normal)
        cancelButton.setTitleColor(.white, for: .normal)
        cancelButton.backgroundColor = UIColor.black.withAlphaComponent(0.6)
        cancelButton.layer.cornerRadius = 8
        cancelButton.translatesAutoresizingMaskIntoConstraints = false
        cancelButton.addTarget(self, action: #selector(cancelTapped), for: .touchUpInside)
        view.addSubview(cancelButton)

        NSLayoutConstraint.activate([
            cancelButton.bottomAnchor.constraint(equalTo: view.safeAreaLayoutGuide.bottomAnchor, constant: -20),
            cancelButton.centerXAnchor.constraint(equalTo: view.centerXAnchor),
            cancelButton.widthAnchor.constraint(equalToConstant: 120),
            cancelButton.heightAnchor.constraint(equalToConstant: 44)
        ])

        // Scan area overlay
        let overlayView = ScanOverlayView()
        overlayView.translatesAutoresizingMaskIntoConstraints = false
        view.addSubview(overlayView)
        NSLayoutConstraint.activate([
            overlayView.topAnchor.constraint(equalTo: view.topAnchor),
            overlayView.bottomAnchor.constraint(equalTo: view.bottomAnchor),
            overlayView.leadingAnchor.constraint(equalTo: view.leadingAnchor),
            overlayView.trailingAnchor.constraint(equalTo: view.trailingAnchor)
        ])
    }

    @objc private func cancelTapped() {
        dismiss(animated: true) { [weak self] in
            self?.delegate?.barcodeScannerDidCancel()
        }
    }

    private func startScanning() {
        DispatchQueue.global(qos: .userInitiated).async { [weak self] in
            self?.captureSession?.startRunning()
        }
    }

    private func stopScanning() {
        captureSession?.stopRunning()
    }

    private func found(code: String) {
        stopScanning()

        // Haptic feedback
        let generator = UINotificationFeedbackGenerator()
        generator.notificationOccurred(.success)

        dismiss(animated: true) { [weak self] in
            self?.delegate?.barcodeScannerDidScan(code)
        }
    }
}

extension BarcodeScannerViewController: AVCaptureMetadataOutputObjectsDelegate {
    func metadataOutput(_ output: AVCaptureMetadataOutput, didOutput metadataObjects: [AVMetadataObject], from connection: AVCaptureConnection) {
        if let metadataObject = metadataObjects.first,
           let readableObject = metadataObject as? AVMetadataMachineReadableCodeObject,
           let stringValue = readableObject.stringValue {
            found(code: stringValue)
        }
    }
}

// MARK: - Scan Overlay View

class ScanOverlayView: UIView {
    private let scanAreaSize: CGFloat = 250

    override init(frame: CGRect) {
        super.init(frame: frame)
        backgroundColor = .clear
    }

    required init?(coder: NSCoder) {
        fatalError("init(coder:) has not been implemented")
    }

    override func draw(_ rect: CGRect) {
        guard let context = UIGraphicsGetCurrentContext() else { return }

        // Semi-transparent overlay
        context.setFillColor(UIColor.black.withAlphaComponent(0.5).cgColor)
        context.fill(rect)

        // Clear scan area
        let scanRect = CGRect(
            x: (rect.width - scanAreaSize) / 2,
            y: (rect.height - scanAreaSize) / 2,
            width: scanAreaSize,
            height: scanAreaSize
        )
        context.clear(scanRect)

        // Corner brackets
        let cornerLength: CGFloat = 30
        let lineWidth: CGFloat = 4
        context.setStrokeColor(UIColor.white.cgColor)
        context.setLineWidth(lineWidth)

        // Top-left
        context.move(to: CGPoint(x: scanRect.minX, y: scanRect.minY + cornerLength))
        context.addLine(to: CGPoint(x: scanRect.minX, y: scanRect.minY))
        context.addLine(to: CGPoint(x: scanRect.minX + cornerLength, y: scanRect.minY))
        context.strokePath()

        // Top-right
        context.move(to: CGPoint(x: scanRect.maxX - cornerLength, y: scanRect.minY))
        context.addLine(to: CGPoint(x: scanRect.maxX, y: scanRect.minY))
        context.addLine(to: CGPoint(x: scanRect.maxX, y: scanRect.minY + cornerLength))
        context.strokePath()

        // Bottom-left
        context.move(to: CGPoint(x: scanRect.minX, y: scanRect.maxY - cornerLength))
        context.addLine(to: CGPoint(x: scanRect.minX, y: scanRect.maxY))
        context.addLine(to: CGPoint(x: scanRect.minX + cornerLength, y: scanRect.maxY))
        context.strokePath()

        // Bottom-right
        context.move(to: CGPoint(x: scanRect.maxX - cornerLength, y: scanRect.maxY))
        context.addLine(to: CGPoint(x: scanRect.maxX, y: scanRect.maxY))
        context.addLine(to: CGPoint(x: scanRect.maxX, y: scanRect.maxY - cornerLength))
        context.strokePath()
    }
}
