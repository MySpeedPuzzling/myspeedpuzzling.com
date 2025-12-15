import Foundation
import WebKit
import StoreKit

/// JavaScript bridge for in-app purchases
/// Receives purchase requests from web JavaScript and initiates native StoreKit flows
class BillingBridge: NSObject, WKScriptMessageHandler {
    weak var webView: WKWebView?

    @MainActor
    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        guard message.name == "billing",
              let body = message.body as? [String: Any],
              let action = body["action"] as? String else {
            return
        }

        switch action {
        case "purchase":
            if let productId = body["productId"] as? String {
                Task {
                    await handlePurchase(productId: productId)
                }
            }
        case "restore":
            Task {
                await handleRestore()
            }
        case "checkSubscription":
            Task {
                await checkSubscriptionStatus()
            }
        default:
            break
        }
    }

    @MainActor
    private func handlePurchase(productId: String) async {
        let storeKit = StoreKitManager.shared

        // Load products if not already loaded
        if storeKit.products.isEmpty {
            await storeKit.loadProducts()
        }

        guard let product = storeKit.products.first(where: { $0.id == productId }) else {
            sendPurchaseError("Product not found: \(productId)")
            return
        }

        do {
            if let transaction = try await storeKit.purchase(product) {
                sendPurchaseSuccess(productId: productId, transactionId: String(transaction.id))
            } else {
                // User cancelled or pending
                sendPurchaseCancelled()
            }
        } catch {
            sendPurchaseError(error.localizedDescription)
        }
    }

    @MainActor
    private func handleRestore() async {
        let storeKit = StoreKitManager.shared
        await storeKit.restorePurchases()

        let hasSubscription = await storeKit.hasActiveSubscription()
        if hasSubscription {
            sendRestoreSuccess()
        } else {
            sendRestoreError("No active subscription found")
        }
    }

    @MainActor
    private func checkSubscriptionStatus() async {
        let hasSubscription = await StoreKitManager.shared.hasActiveSubscription()
        sendSubscriptionStatus(active: hasSubscription)
    }

    // MARK: - JavaScript Callbacks

    private func sendPurchaseSuccess(productId: String, transactionId: String) {
        let js = "window.onIosPurchaseSuccess && window.onIosPurchaseSuccess('\(productId)', '\(transactionId)')"
        DispatchQueue.main.async {
            self.webView?.evaluateJavaScript(js)
        }
    }

    private func sendPurchaseCancelled() {
        let js = "window.onIosPurchaseCancelled && window.onIosPurchaseCancelled()"
        DispatchQueue.main.async {
            self.webView?.evaluateJavaScript(js)
        }
    }

    private func sendPurchaseError(_ error: String) {
        let escapedError = error.replacingOccurrences(of: "'", with: "\\'")
        let js = "window.onIosPurchaseError && window.onIosPurchaseError('\(escapedError)')"
        DispatchQueue.main.async {
            self.webView?.evaluateJavaScript(js)
        }
    }

    private func sendRestoreSuccess() {
        let js = "window.onIosRestoreSuccess && window.onIosRestoreSuccess()"
        DispatchQueue.main.async {
            self.webView?.evaluateJavaScript(js)
        }
    }

    private func sendRestoreError(_ error: String) {
        let escapedError = error.replacingOccurrences(of: "'", with: "\\'")
        let js = "window.onIosRestoreError && window.onIosRestoreError('\(escapedError)')"
        DispatchQueue.main.async {
            self.webView?.evaluateJavaScript(js)
        }
    }

    private func sendSubscriptionStatus(active: Bool) {
        let js = "window.onIosSubscriptionStatus && window.onIosSubscriptionStatus(\(active))"
        DispatchQueue.main.async {
            self.webView?.evaluateJavaScript(js)
        }
    }
}
