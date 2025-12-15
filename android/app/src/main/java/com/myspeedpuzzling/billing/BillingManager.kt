package com.myspeedpuzzling.billing

import android.app.Activity
import com.android.billingclient.api.*
import kotlinx.coroutines.*
import okhttp3.MediaType.Companion.toMediaType
import okhttp3.OkHttpClient
import okhttp3.Request
import okhttp3.RequestBody.Companion.toRequestBody
import org.json.JSONObject

/**
 * Manages Google Play Billing for subscriptions.
 */
class BillingManager(private val activity: Activity) : PurchasesUpdatedListener {
    private var billingClient: BillingClient? = null
    private var callback: BillingCallback? = null
    private val scope = CoroutineScope(Dispatchers.Main + SupervisorJob())
    private val httpClient = OkHttpClient()

    companion object {
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
        billingClient = BillingClient.newBuilder(activity)
            .setListener(this)
            .enablePendingPurchases()
            .build()

        billingClient?.startConnection(object : BillingClientStateListener {
            override fun onBillingSetupFinished(billingResult: BillingResult) {
                if (billingResult.responseCode == BillingClient.BillingResponseCode.OK) {
                    // Billing client is ready
                }
            }

            override fun onBillingServiceDisconnected() {
                // Try to reconnect
                billingClient?.startConnection(this)
            }
        })
    }

    fun launchPurchaseFlow(productId: String) {
        val billingClient = this.billingClient ?: return

        scope.launch {
            val productList = listOf(
                QueryProductDetailsParams.Product.newBuilder()
                    .setProductId(productId)
                    .setProductType(BillingClient.ProductType.SUBS)
                    .build()
            )

            val params = QueryProductDetailsParams.newBuilder()
                .setProductList(productList)
                .build()

            billingClient.queryProductDetailsAsync(params) { billingResult, productDetailsList ->
                if (billingResult.responseCode == BillingClient.BillingResponseCode.OK &&
                    productDetailsList.isNotEmpty()
                ) {
                    val productDetails = productDetailsList.first()
                    val offerToken = productDetails.subscriptionOfferDetails?.firstOrNull()?.offerToken
                        ?: return@queryProductDetailsAsync

                    val productDetailsParamsList = listOf(
                        BillingFlowParams.ProductDetailsParams.newBuilder()
                            .setProductDetails(productDetails)
                            .setOfferToken(offerToken)
                            .build()
                    )

                    val billingFlowParams = BillingFlowParams.newBuilder()
                        .setProductDetailsParamsList(productDetailsParamsList)
                        .build()

                    billingClient.launchBillingFlow(activity, billingFlowParams)
                } else {
                    callback?.onPurchaseError("Product not found: $productId")
                }
            }
        }
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
            // Acknowledge the purchase if not already
            if (!purchase.isAcknowledged) {
                val acknowledgePurchaseParams = AcknowledgePurchaseParams.newBuilder()
                    .setPurchaseToken(purchase.purchaseToken)
                    .build()

                billingClient?.acknowledgePurchase(acknowledgePurchaseParams) { result ->
                    if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                        // Purchase acknowledged successfully
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
                    if (response.isSuccessful) {
                        callback?.onPurchaseSuccess(productId, purchase.purchaseToken)
                    } else {
                        // Still consider it a success for the user, backend sync will retry
                        callback?.onPurchaseSuccess(productId, purchase.purchaseToken)
                    }
                }
            } catch (e: Exception) {
                withContext(Dispatchers.Main) {
                    // Still report success - the purchase went through on Play Store
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
                    // Re-verify with backend
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
