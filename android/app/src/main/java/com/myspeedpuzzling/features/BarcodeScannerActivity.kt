package com.myspeedpuzzling.features

import android.app.Activity
import android.content.Intent
import android.os.Bundle
import android.util.Size
import android.view.View
import android.widget.Button
import android.widget.FrameLayout
import androidx.appcompat.app.AppCompatActivity
import androidx.camera.core.CameraSelector
import androidx.camera.core.ImageAnalysis
import androidx.camera.core.Preview
import androidx.camera.lifecycle.ProcessCameraProvider
import androidx.camera.view.PreviewView
import androidx.core.content.ContextCompat
import com.google.mlkit.vision.barcode.BarcodeScanning
import com.google.mlkit.vision.barcode.common.Barcode
import com.google.mlkit.vision.common.InputImage
import com.myspeedpuzzling.app.R
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors

/**
 * Full-screen barcode scanner activity using CameraX and ML Kit.
 * Scans EAN-8 and EAN-13 barcodes commonly used on puzzle boxes.
 */
class BarcodeScannerActivity : AppCompatActivity() {
    private lateinit var cameraExecutor: ExecutorService
    private lateinit var previewView: PreviewView
    private var hasScanned = false

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_barcode_scanner)

        previewView = findViewById(R.id.preview_view)
        cameraExecutor = Executors.newSingleThreadExecutor()

        setupCancelButton()
        startCamera()
    }

    private fun setupCancelButton() {
        findViewById<Button>(R.id.cancel_button).setOnClickListener {
            setResult(Activity.RESULT_CANCELED)
            finish()
        }
    }

    private fun startCamera() {
        val cameraProviderFuture = ProcessCameraProvider.getInstance(this)

        cameraProviderFuture.addListener({
            val cameraProvider = cameraProviderFuture.get()

            val preview = Preview.Builder()
                .build()
                .also {
                    it.setSurfaceProvider(previewView.surfaceProvider)
                }

            val imageAnalyzer = ImageAnalysis.Builder()
                .setTargetResolution(Size(1280, 720))
                .setBackpressureStrategy(ImageAnalysis.STRATEGY_KEEP_ONLY_LATEST)
                .build()
                .also {
                    it.setAnalyzer(cameraExecutor, BarcodeAnalyzer { barcode ->
                        if (!hasScanned) {
                            hasScanned = true
                            onBarcodeDetected(barcode)
                        }
                    })
                }

            val cameraSelector = CameraSelector.DEFAULT_BACK_CAMERA

            try {
                cameraProvider.unbindAll()
                cameraProvider.bindToLifecycle(
                    this,
                    cameraSelector,
                    preview,
                    imageAnalyzer
                )
            } catch (e: Exception) {
                e.printStackTrace()
            }
        }, ContextCompat.getMainExecutor(this))
    }

    private fun onBarcodeDetected(barcode: String) {
        runOnUiThread {
            // Haptic feedback
            previewView.performHapticFeedback(android.view.HapticFeedbackConstants.CONFIRM)

            // Return result
            val intent = Intent().apply {
                putExtra(BarcodeScannerBridge.RESULT_BARCODE, barcode)
            }
            setResult(Activity.RESULT_OK, intent)
            finish()
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        cameraExecutor.shutdown()
    }

    /**
     * Analyzes camera frames for EAN barcodes using ML Kit.
     */
    private class BarcodeAnalyzer(
        private val onBarcodeDetected: (String) -> Unit
    ) : ImageAnalysis.Analyzer {
        private val scanner = BarcodeScanning.getClient()

        @androidx.camera.core.ExperimentalGetImage
        override fun analyze(imageProxy: androidx.camera.core.ImageProxy) {
            val mediaImage = imageProxy.image
            if (mediaImage != null) {
                val image = InputImage.fromMediaImage(
                    mediaImage,
                    imageProxy.imageInfo.rotationDegrees
                )

                scanner.process(image)
                    .addOnSuccessListener { barcodes ->
                        for (barcode in barcodes) {
                            // Only accept EAN-8 and EAN-13 barcodes
                            if (barcode.format == Barcode.FORMAT_EAN_8 ||
                                barcode.format == Barcode.FORMAT_EAN_13
                            ) {
                                barcode.rawValue?.let { value ->
                                    onBarcodeDetected(value)
                                    return@addOnSuccessListener
                                }
                            }
                        }
                    }
                    .addOnCompleteListener {
                        imageProxy.close()
                    }
            } else {
                imageProxy.close()
            }
        }
    }
}
