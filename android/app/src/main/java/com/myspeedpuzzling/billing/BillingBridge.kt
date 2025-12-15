package com.myspeedpuzzling.billing

import android.content.Context
import android.webkit.JavascriptInterface
import android.webkit.WebView
import java.lang.ref.WeakReference

/**
 * JavaScript bridge for Google Play Billing on Android.
 * Receives purchase requests from web JavaScript and initiates native billing flows.
 */
class BillingBridge(context: Context) {
    private val contextRef = WeakReference(context)
    private var webViewRef: WeakReference<WebView>? = null
    private var billingManager: BillingManager? = null

    init {
        billingManager = BillingManager(context).apply {
            setCallback(object : BillingManager.BillingCallback {
                override fun onPurchaseSuccess(productId: String, purchaseToken: String) {
                    sendPurchaseSuccess(productId, purchaseToken)
                }

                override fun onPurchaseCancelled() {
                    sendPurchaseCancelled()
                }

                override fun onPurchaseError(error: String) {
                    sendPurchaseError(error)
                }

                override fun onRestoreSuccess() {
                    sendRestoreSuccess()
                }

                override fun onRestoreError(error: String) {
                    sendRestoreError(error)
                }

                override fun onSubscriptionStatus(active: Boolean) {
                    sendSubscriptionStatus(active)
                }
            })
        }
    }

    fun setWebView(webView: WebView) {
        this.webViewRef = WeakReference(webView)
    }

    @JavascriptInterface
    fun purchase(productId: String) {
        billingManager?.launchPurchaseFlow(productId)
    }

    @JavascriptInterface
    fun restorePurchases() {
        billingManager?.restorePurchases()
    }

    @JavascriptInterface
    fun checkSubscription() {
        billingManager?.checkSubscriptionStatus()
    }

    fun destroy() {
        billingManager?.destroy()
    }

    // JavaScript callbacks

    private fun sendPurchaseSuccess(productId: String, purchaseToken: String) {
        val webView = webViewRef?.get() ?: return
        val js = "window.onAndroidPurchaseSuccess && window.onAndroidPurchaseSuccess('$productId', '$purchaseToken')"
        webView.post {
            webView.evaluateJavascript(js, null)
        }
    }

    private fun sendPurchaseCancelled() {
        val webView = webViewRef?.get() ?: return
        val js = "window.onAndroidPurchaseCancelled && window.onAndroidPurchaseCancelled()"
        webView.post {
            webView.evaluateJavascript(js, null)
        }
    }

    private fun sendPurchaseError(error: String) {
        val webView = webViewRef?.get() ?: return
        val escapedError = error.replace("'", "\\'")
        val js = "window.onAndroidPurchaseError && window.onAndroidPurchaseError('$escapedError')"
        webView.post {
            webView.evaluateJavascript(js, null)
        }
    }

    private fun sendRestoreSuccess() {
        val webView = webViewRef?.get() ?: return
        val js = "window.onAndroidRestoreSuccess && window.onAndroidRestoreSuccess()"
        webView.post {
            webView.evaluateJavascript(js, null)
        }
    }

    private fun sendRestoreError(error: String) {
        val webView = webViewRef?.get() ?: return
        val escapedError = error.replace("'", "\\'")
        val js = "window.onAndroidRestoreError && window.onAndroidRestoreError('$escapedError')"
        webView.post {
            webView.evaluateJavascript(js, null)
        }
    }

    private fun sendSubscriptionStatus(active: Boolean) {
        val webView = webViewRef?.get() ?: return
        val js = "window.onAndroidSubscriptionStatus && window.onAndroidSubscriptionStatus($active)"
        webView.post {
            webView.evaluateJavascript(js, null)
        }
    }
}
