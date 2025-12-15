package com.myspeedpuzzling.features

import android.Manifest
import android.app.Activity
import android.content.Intent
import android.content.pm.PackageManager
import android.webkit.JavascriptInterface
import android.webkit.WebView
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat

/**
 * JavaScript bridge for native barcode scanning on Android.
 * Receives messages from web JavaScript and triggers native scanner.
 */
class BarcodeScannerBridge(private val activity: Activity) {
    private var webView: WebView? = null

    fun setWebView(webView: WebView) {
        this.webView = webView
    }

    @JavascriptInterface
    fun openScanner() {
        activity.runOnUiThread {
            if (checkCameraPermission()) {
                launchScanner()
            } else {
                requestCameraPermission()
            }
        }
    }

    private fun checkCameraPermission(): Boolean {
        return ContextCompat.checkSelfPermission(
            activity,
            Manifest.permission.CAMERA
        ) == PackageManager.PERMISSION_GRANTED
    }

    private fun requestCameraPermission() {
        if (activity is AppCompatActivity) {
            // For modern permission handling, the activity should register for result
            // This is a simplified version - in production, use ActivityResultLauncher
            activity.requestPermissions(
                arrayOf(Manifest.permission.CAMERA),
                CAMERA_PERMISSION_REQUEST
            )
        }
    }

    private fun launchScanner() {
        val intent = Intent(activity, BarcodeScannerActivity::class.java)
        activity.startActivityForResult(intent, SCANNER_REQUEST_CODE)
    }

    fun handleActivityResult(requestCode: Int, resultCode: Int, data: Intent?) {
        if (requestCode == SCANNER_REQUEST_CODE) {
            if (resultCode == Activity.RESULT_OK) {
                val barcode = data?.getStringExtra(RESULT_BARCODE)
                if (barcode != null) {
                    sendScanResult(barcode)
                } else {
                    sendScanCancelled()
                }
            } else {
                sendScanCancelled()
            }
        }
    }

    fun handlePermissionResult(requestCode: Int, grantResults: IntArray) {
        if (requestCode == CAMERA_PERMISSION_REQUEST) {
            if (grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                launchScanner()
            } else {
                sendScanCancelled()
            }
        }
    }

    private fun sendScanResult(code: String) {
        val escapedCode = code.replace("'", "\\'")
        val js = "window.onNativeScanResult && window.onNativeScanResult('$escapedCode')"
        webView?.post {
            webView?.evaluateJavascript(js, null)
        }
    }

    private fun sendScanCancelled() {
        val js = "window.onNativeScanCancelled && window.onNativeScanCancelled()"
        webView?.post {
            webView?.evaluateJavascript(js, null)
        }
    }

    companion object {
        const val SCANNER_REQUEST_CODE = 1001
        const val RESULT_BARCODE = "barcode"
        private const val CAMERA_PERMISSION_REQUEST = 1002
    }
}
