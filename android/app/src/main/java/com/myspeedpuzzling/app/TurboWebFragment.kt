package com.myspeedpuzzling.app

import android.annotation.SuppressLint
import android.os.Bundle
import android.view.View
import android.webkit.WebView
import dev.hotwire.turbo.fragments.TurboWebFragment
import dev.hotwire.turbo.nav.TurboNavGraphDestination
import com.myspeedpuzzling.billing.BillingBridge
import com.myspeedpuzzling.features.BarcodeScannerBridge

@TurboNavGraphDestination(uri = "turbo://fragment/web")
class WebFragment : TurboWebFragment() {
    private var scannerBridge: BarcodeScannerBridge? = null
    private var billingBridge: BillingBridge? = null

    override fun onViewCreated(view: View, savedInstanceState: Bundle?) {
        super.onViewCreated(view, savedInstanceState)
        setupJavaScriptBridges()
    }

    @SuppressLint("SetJavaScriptEnabled", "JavascriptInterface")
    private fun setupJavaScriptBridges() {
        val webView = turboWebView ?: return
        val activity = requireActivity()

        // Initialize bridges
        scannerBridge = BarcodeScannerBridge(activity).also { bridge ->
            bridge.setWebView(webView)
            webView.addJavascriptInterface(bridge, "AndroidScanner")
        }

        billingBridge = BillingBridge(activity).also { bridge ->
            bridge.setWebView(webView)
            webView.addJavascriptInterface(bridge, "AndroidBilling")
        }

        // Inject JavaScript helpers
        injectNativeHelpers(webView)
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
