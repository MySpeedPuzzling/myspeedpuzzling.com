import Foundation
import StoreKit

/// Manages StoreKit 2 in-app purchases
@MainActor
class StoreKitManager: ObservableObject {
    static let shared = StoreKitManager()

    // Product IDs matching App Store Connect configuration
    static let monthlyProductId = "com.myspeedpuzzling.premium.monthly"
    static let yearlyProductId = "com.myspeedpuzzling.premium.yearly"

    @Published private(set) var products: [Product] = []
    @Published private(set) var purchasedProductIds: Set<String> = []
    @Published private(set) var isLoading = false
    @Published private(set) var error: String?

    private var updateListenerTask: Task<Void, Error>?

    private init() {
        updateListenerTask = listenForTransactions()
    }

    deinit {
        updateListenerTask?.cancel()
    }

    /// Load products from App Store
    func loadProducts() async {
        isLoading = true
        error = nil

        do {
            let productIds = [Self.monthlyProductId, Self.yearlyProductId]
            products = try await Product.products(for: productIds)
            products.sort { $0.price < $1.price }
            isLoading = false
        } catch {
            self.error = "Failed to load products: \(error.localizedDescription)"
            isLoading = false
        }
    }

    /// Purchase a product
    func purchase(_ product: Product) async throws -> Transaction? {
        let result = try await product.purchase()

        switch result {
        case .success(let verification):
            let transaction = try checkVerified(verification)
            await updatePurchasedProducts()
            await transaction.finish()

            // Send receipt to backend for verification
            await verifyWithBackend(transaction)

            return transaction

        case .userCancelled:
            return nil

        case .pending:
            return nil

        @unknown default:
            return nil
        }
    }

    /// Restore purchases
    func restorePurchases() async {
        do {
            try await AppStore.sync()
            await updatePurchasedProducts()
        } catch {
            self.error = "Failed to restore purchases: \(error.localizedDescription)"
        }
    }

    /// Check if user has active subscription
    func hasActiveSubscription() async -> Bool {
        for await result in Transaction.currentEntitlements {
            if case .verified(let transaction) = result {
                if transaction.productID == Self.monthlyProductId ||
                   transaction.productID == Self.yearlyProductId {
                    return true
                }
            }
        }
        return false
    }

    // MARK: - Private Methods

    private func listenForTransactions() -> Task<Void, Error> {
        Task.detached {
            for await result in Transaction.updates {
                do {
                    let transaction = try await self.checkVerified(result)
                    await self.updatePurchasedProducts()
                    await transaction.finish()
                    await self.verifyWithBackend(transaction)
                } catch {
                    print("Transaction verification failed: \(error)")
                }
            }
        }
    }

    private func checkVerified<T>(_ result: VerificationResult<T>) throws -> T {
        switch result {
        case .unverified:
            throw StoreError.failedVerification
        case .verified(let safe):
            return safe
        }
    }

    private func updatePurchasedProducts() async {
        var purchased: Set<String> = []

        for await result in Transaction.currentEntitlements {
            if case .verified(let transaction) = result {
                purchased.insert(transaction.productID)
            }
        }

        purchasedProductIds = purchased
    }

    /// Send transaction to backend for server-side verification
    private func verifyWithBackend(_ transaction: Transaction) async {
        guard let appStoreReceiptURL = Bundle.main.appStoreReceiptURL,
              FileManager.default.fileExists(atPath: appStoreReceiptURL.path),
              let receiptData = try? Data(contentsOf: appStoreReceiptURL) else {
            print("Could not read App Store receipt")
            return
        }

        let receiptString = receiptData.base64EncodedString()

        guard let url = URL(string: "https://myspeedpuzzling.com/api/ios/verify-receipt") else {
            return
        }

        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")

        let payload: [String: Any] = [
            "receiptData": receiptString,
            "productId": transaction.productID,
            "transactionId": String(transaction.id),
            "originalTransactionId": transaction.originalID.map { String($0) } ?? ""
        ]

        do {
            request.httpBody = try JSONSerialization.data(withJSONObject: payload)
            let (_, response) = try await URLSession.shared.data(for: request)

            if let httpResponse = response as? HTTPURLResponse {
                if httpResponse.statusCode == 200 {
                    print("Backend verification successful")
                } else {
                    print("Backend verification failed with status: \(httpResponse.statusCode)")
                }
            }
        } catch {
            print("Backend verification request failed: \(error)")
        }
    }
}

// MARK: - Store Errors

enum StoreError: Error {
    case failedVerification
    case productNotFound
    case purchaseFailed
}

extension StoreError: LocalizedError {
    var errorDescription: String? {
        switch self {
        case .failedVerification:
            return "Transaction verification failed"
        case .productNotFound:
            return "Product not found"
        case .purchaseFailed:
            return "Purchase failed"
        }
    }
}
