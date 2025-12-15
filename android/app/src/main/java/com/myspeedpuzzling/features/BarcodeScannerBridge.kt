package com.myspeedpuzzling.features

import android.content.Context
import android.content.Intent
import android.webkit.JavascriptInterface
import android.webkit.WebView
import java.lang.ref.WeakReference

/**
 * JavaScript bridge for native barcode scanning on Android.
 * Receives messages from web JavaScript and triggers native scanner.
 */
class BarcodeScannerBridge(context: Context) {
    private val contextRef = WeakReference(context)
    private var webViewRef: WeakReference<WebView>? = null

    companion object {
        const val SCANNER_REQUEST_CODE = 1001
        const val RESULT_BARCODE = "barcode"

        // Static reference for callbacks from scanner activity
        private var currentBridge: WeakReference<BarcodeScannerBridge>? = null

        fun onScanResult(barcode: String) {
            currentBridge?.get()?.sendScanResult(barcode)
        }

        fun onScanCancelled() {
            currentBridge?.get()?.sendScanCancelled()
        }
    }

    fun setWebView(webView: WebView) {
        this.webViewRef = WeakReference(webView)
    }

    @JavascriptInterface
    fun openScanner() {
        val context = contextRef.get() ?: return
        currentBridge = WeakReference(this)

        val intent = Intent(context, BarcodeScannerActivity::class.java)
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        context.startActivity(intent)
    }

    private fun sendScanResult(code: String) {
        val webView = webViewRef?.get() ?: return
        val escapedCode = code.replace("'", "\\'")
        val js = "window.onNativeScanResult && window.onNativeScanResult('$escapedCode')"
        webView.post {
            webView.evaluateJavascript(js, null)
        }
    }

    private fun sendScanCancelled() {
        val webView = webViewRef?.get() ?: return
        val js = "window.onNativeScanCancelled && window.onNativeScanCancelled()"
        webView.post {
            webView.evaluateJavascript(js, null)
        }
    }
}
