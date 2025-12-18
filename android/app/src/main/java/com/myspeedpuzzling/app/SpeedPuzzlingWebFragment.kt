package com.myspeedpuzzling.app

import android.annotation.SuppressLint
import android.os.Bundle
import android.view.View
import android.webkit.WebView
import dev.hotwire.navigation.destinations.HotwireDestinationDeepLink
import dev.hotwire.navigation.fragments.HotwireWebFragment
import com.myspeedpuzzling.billing.BillingBridge
import com.myspeedpuzzling.features.BarcodeScannerBridge

@HotwireDestinationDeepLink(uri = "hotwire://fragment/web")
class SpeedPuzzlingWebFragment : HotwireWebFragment() {
    private var scannerBridge: BarcodeScannerBridge? = null
    private var billingBridge: BillingBridge? = null

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)

        // Use post to ensure the view hierarchy is fully initialized
        view.post {
            setupJavaScriptBridges()
        }
    }

    @SuppressLint("JavascriptInterface")
    private fun setupJavaScriptBridges() {
        // Try to find the WebView in the fragment's view hierarchy
        val webView = findWebView(view) ?: return
        val context = requireContext()

        // Initialize scanner bridge
        scannerBridge = BarcodeScannerBridge(context).also { bridge ->
            bridge.setWebView(webView)
            webView.addJavascriptInterface(bridge, "AndroidScanner")
        }

        // Initialize billing bridge
        billingBridge = BillingBridge(context).also { bridge ->
            bridge.setWebView(webView)
            webView.addJavascriptInterface(bridge, "AndroidBilling")
        }

        // Inject JavaScript helpers when page loads
        injectNativeHelpers(webView)
    }

    private fun findWebView(view: View?): WebView? {
        if (view == null) return null
        if (view is WebView) return view

        if (view is android.view.ViewGroup) {
            for (i in 0 until view.childCount) {
                val found = findWebView(view.getChildAt(i))
                if (found != null) return found
            }
        }
        return null
    }

    private fun injectNativeHelpers(webView: WebView) {
        val script = """
            window.isNativeApp = true;
            window.nativePlatform = 'android';

            // Scanner bridge
            window.openNativeScanner = function() {
                if (window.AndroidScanner) {
                    AndroidScanner.openScanner();
                }
            };

            // Billing bridge
            window.purchaseProduct = function(productId) {
                if (window.AndroidBilling) {
                    AndroidBilling.purchase(productId);
                }
            };

            window.restorePurchases = function() {
                if (window.AndroidBilling) {
                    AndroidBilling.restorePurchases();
                }
            };

            window.checkSubscription = function() {
                if (window.AndroidBilling) {
                    AndroidBilling.checkSubscription();
                }
            };
        """.trimIndent()

        webView.evaluateJavascript(script, null)
    }

    override fun onDestroyView() {
        billingBridge?.destroy()
        scannerBridge = null
        billingBridge = null
        super.onDestroyView()
    }
}
