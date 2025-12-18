import UIKit
import WebKit
import HotwireNative

/// Custom web view controller that integrates JavaScript bridges for scanner and billing
class WebViewController: VisitableViewController {
    private let scannerBridge = BarcodeScannerBridge()
    private let billingBridge = BillingBridge()

    override func viewDidLoad() {
        super.viewDidLoad()
        setupBridges()
    }

    private func setupBridges() {
        guard let webView = visitableView?.webView else { return }

        // Set up scanner bridge
        scannerBridge.webView = webView
        webView.configuration.userContentController.add(scannerBridge, name: "scanner")

        // Set up billing bridge
        billingBridge.webView = webView
        webView.configuration.userContentController.add(billingBridge, name: "billing")

        // Inject JavaScript helpers for native bridge detection
        let script = WKUserScript(
            source: """
                window.isNativeApp = true;
                window.nativePlatform = 'ios';

                // Scanner bridge
                window.openNativeScanner = function() {
                    window.webkit.messageHandlers.scanner.postMessage({ action: 'open' });
                };

                // Billing bridge
                window.purchaseProduct = function(productId) {
                    window.webkit.messageHandlers.billing.postMessage({ action: 'purchase', productId: productId });
                };

                window.restorePurchases = function() {
                    window.webkit.messageHandlers.billing.postMessage({ action: 'restore' });
                };

                window.checkSubscription = function() {
                    window.webkit.messageHandlers.billing.postMessage({ action: 'checkSubscription' });
                };
            """,
            injectionTime: .atDocumentStart,
            forMainFrameOnly: true
        )
        webView.configuration.userContentController.addUserScript(script)
    }
}
