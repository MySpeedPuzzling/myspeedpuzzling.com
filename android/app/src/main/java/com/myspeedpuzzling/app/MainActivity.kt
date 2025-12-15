package com.myspeedpuzzling.app

import android.os.Bundle
import android.webkit.WebView
import androidx.appcompat.app.AppCompatActivity
import com.myspeedpuzzling.billing.BillingBridge
import com.myspeedpuzzling.features.BarcodeScannerBridge
import dev.hotwire.turbo.activities.TurboActivity
import dev.hotwire.turbo.delegates.TurboActivityDelegate

class MainActivity : AppCompatActivity(), TurboActivity {
    override lateinit var delegate: TurboActivityDelegate

    private lateinit var scannerBridge: BarcodeScannerBridge
    private lateinit var billingBridge: BillingBridge

    companion object {
        private const val BASE_URL = "https://myspeedpuzzling.com"
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        // Initialize Turbo delegate
        delegate = TurboActivityDelegate(this, R.id.main_nav_host)

        // Initialize bridges
        scannerBridge = BarcodeScannerBridge(this)
        billingBridge = BillingBridge(this)

        // Configure WebView
        setupWebView()
    }

    private fun setupWebView() {
        // The WebView setup will be done through Turbo's navigation
        // JavaScript bridges are added in TurboWebFragment
    }

    override fun onDestroy() {
        billingBridge.destroy()
        super.onDestroy()
    }
}
