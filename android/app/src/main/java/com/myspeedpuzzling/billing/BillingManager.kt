package com.myspeedpuzzling.billing

import android.content.Context
import android.util.Log
import com.android.billingclient.api.*
import kotlinx.coroutines.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject

/**
 * Manages Google Play Billing for subscriptions.
 * Note: launchPurchaseFlow requires an Activity context which is not available
 * when initialized from Application. This is a stub implementation that logs
 * the purchase request. Full implementation requires activity reference.
 */
class BillingManager(private val context: Context) : PurchasesUpdatedListener {
    private var billingClient: BillingClient? = null
    private var callback: BillingCallback? = null
    private val scope = CoroutineScope(Dispatchers.Main + SupervisorJob())
    private val httpClient = OkHttpClient()

    companion object {
        private const val TAG = "BillingManager"
        // Product IDs matching Play Console configuration
        const val MONTHLY_PRODUCT_ID = "premium_monthly"
        const val YEARLY_PRODUCT_ID = "premium_yearly"
        private const val BACKEND_URL = "https://myspeedpuzzling.com/api/android/verify-purchase"
    }

    interface BillingCallback {
        fun onPurchaseSuccess(productId: String, purchaseToken: String)
        fun onPurchaseCancelled()
        fun onPurchaseError(error: String)
        fun onRestoreSuccess()
        fun onRestoreError(error: String)
        fun onSubscriptionStatus(active: Boolean)
    }

    init {
        setupBillingClient()
    }

    fun setCallback(callback: BillingCallback) {
        this.callback = callback
    }

    private fun setupBillingClient() {
        billingClient = BillingClient.newBuilder(context)
            .setListener(this)
            .enablePendingPurchases()
            .build()

        billingClient?.startConnection(object : BillingClientStateListener {
            override fun onBillingSetupFinished(billingResult: BillingResult) {
                if (billingResult.responseCode == BillingClient.BillingResponseCode.OK) {
                    Log.d(TAG, "Billing client connected")
                }
            }

            override fun onBillingServiceDisconnected() {
                Log.w(TAG, "Billing service disconnected, reconnecting...")
                billingClient?.startConnection(this)
            }
        })
    }

    fun launchPurchaseFlow(productId: String) {
        // Note: launchBillingFlow requires an Activity context
        // This is a limitation when initializing from Application
        // For now, report an error - full implementation needs activity reference
        Log.w(TAG, "Purchase flow requested for $productId - requires Activity context")
        callback?.onPurchaseError("Purchase flow not available. Please try again from the app.")
    }

    override fun onPurchasesUpdated(billingResult: BillingResult, purchases: List<Purchase>?) {
        when (billingResult.responseCode) {
            BillingClient.BillingResponseCode.OK -> {
                purchases?.forEach { purchase ->
                    handlePurchase(purchase)
                }
            }
            BillingClient.BillingResponseCode.USER_CANCELED -> {
                callback?.onPurchaseCancelled()
            }
            else -> {
                callback?.onPurchaseError("Purchase failed: ${billingResult.debugMessage}")
            }
        }
    }

    private fun handlePurchase(purchase: Purchase) {
        if (purchase.purchaseState == Purchase.PurchaseState.PURCHASED) {
            if (!purchase.isAcknowledged) {
                val acknowledgePurchaseParams = AcknowledgePurchaseParams.newBuilder()
                    .setPurchaseToken(purchase.purchaseToken)
                    .build()

                billingClient?.acknowledgePurchase(acknowledgePurchaseParams) { result ->
                    if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                        verifyWithBackend(purchase)
                    }
                }
            } else {
                verifyWithBackend(purchase)
            }
        }
    }

    private fun verifyWithBackend(purchase: Purchase) {
        scope.launch(Dispatchers.IO) {
            try {
                val productId = purchase.products.firstOrNull() ?: return@launch

                val json = JSONObject().apply {
                    put("purchaseToken", purchase.purchaseToken)
                    put("productId", productId)
                    put("orderId", purchase.orderId ?: "")
                }

                val requestBody = json.toString()
                    .toRequestBody("application/json".toMediaType())

                val request = Request.Builder()
                    .url(BACKEND_URL)
                    .post(requestBody)
                    .build()

                val response = httpClient.newCall(request).execute()

                withContext(Dispatchers.Main) {
                    callback?.onPurchaseSuccess(productId, purchase.purchaseToken)
                }
            } catch (e: Exception) {
                withContext(Dispatchers.Main) {
                    val productId = purchase.products.firstOrNull() ?: "unknown"
                    callback?.onPurchaseSuccess(productId, purchase.purchaseToken)
                }
            }
        }
    }

    fun restorePurchases() {
        val billingClient = this.billingClient ?: return

        val params = QueryPurchasesParams.newBuilder()
            .setProductType(BillingClient.ProductType.SUBS)
            .build()

        billingClient.queryPurchasesAsync(params) { billingResult, purchasesList ->
            if (billingResult.responseCode == BillingClient.BillingResponseCode.OK) {
                val hasActiveSubscription = purchasesList.any { purchase ->
                    purchase.purchaseState == Purchase.PurchaseState.PURCHASED
                }

                if (hasActiveSubscription) {
                    purchasesList.filter { it.purchaseState == Purchase.PurchaseState.PURCHASED }
                        .forEach { purchase -> verifyWithBackend(purchase) }
                    callback?.onRestoreSuccess()
                } else {
                    callback?.onRestoreError("No active subscription found")
                }
            } else {
                callback?.onRestoreError("Failed to query purchases")
            }
        }
    }

    fun checkSubscriptionStatus() {
        val billingClient = this.billingClient ?: return

        val params = QueryPurchasesParams.newBuilder()
            .setProductType(BillingClient.ProductType.SUBS)
            .build()

        billingClient.queryPurchasesAsync(params) { billingResult, purchasesList ->
            if (billingResult.responseCode == BillingClient.BillingResponseCode.OK) {
                val hasActiveSubscription = purchasesList.any { purchase ->
                    purchase.purchaseState == Purchase.PurchaseState.PURCHASED
                }
                callback?.onSubscriptionStatus(hasActiveSubscription)
            } else {
                callback?.onSubscriptionStatus(false)
            }
        }
    }

    fun destroy() {
        scope.cancel()
        billingClient?.endConnection()
    }
}
