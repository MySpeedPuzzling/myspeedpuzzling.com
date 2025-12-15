package com.myspeedpuzzling.billing

import android.app.Activity
import android.webkit.JavascriptInterface
import android.webkit.WebView

/**
 * JavaScript bridge for Google Play Billing on Android.
 * Receives purchase requests from web JavaScript and initiates native billing flows.
 */
class BillingBridge(private val activity: Activity) {
    private var webView: WebView? = null
    private val billingManager = BillingManager(activity)

    fun setWebView(webView: WebView) {
        this.webView = webView
        billingManager.setCallback(object : BillingManager.BillingCallback {
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

    @JavascriptInterface
    fun purchase(productId: String) {
        activity.runOnUiThread {
            billingManager.launchPurchaseFlow(productId)
        }
    }

    @JavascriptInterface
    fun restorePurchases() {
        activity.runOnUiThread {
            billingManager.restorePurchases()
        }
    }

    @JavascriptInterface
    fun checkSubscription() {
        activity.runOnUiThread {
            billingManager.checkSubscriptionStatus()
        }
    }

    fun destroy() {
        billingManager.destroy()
    }

    // JavaScript callbacks

    private fun sendPurchaseSuccess(productId: String, purchaseToken: String) {
        val js = "window.onAndroidPurchaseSuccess && window.onAndroidPurchaseSuccess('$productId', '$purchaseToken')"
        webView?.post {
            webView?.evaluateJavascript(js, null)
        }
    }

    private fun sendPurchaseCancelled() {
        val js = "window.onAndroidPurchaseCancelled && window.onAndroidPurchaseCancelled()"
        webView?.post {
            webView?.evaluateJavascript(js, null)
        }
    }

    private fun sendPurchaseError(error: String) {
        val escapedError = error.replace("'", "\\'")
        val js = "window.onAndroidPurchaseError && window.onAndroidPurchaseError('$escapedError')"
        webView?.post {
            webView?.evaluateJavascript(js, null)
        }
    }

    private fun sendRestoreSuccess() {
        val js = "window.onAndroidRestoreSuccess && window.onAndroidRestoreSuccess()"
        webView?.post {
            webView?.evaluateJavascript(js, null)
        }
    }

    private fun sendRestoreError(error: String) {
        val escapedError = error.replace("'", "\\'")
        val js = "window.onAndroidRestoreError && window.onAndroidRestoreError('$escapedError')"
        webView?.post {
            webView?.evaluateJavascript(js, null)
        }
    }

    private fun sendSubscriptionStatus(active: Boolean) {
        val js = "window.onAndroidSubscriptionStatus && window.onAndroidSubscriptionStatus($active)"
        webView?.post {
            webView?.evaluateJavascript(js, null)
        }
    }
}
