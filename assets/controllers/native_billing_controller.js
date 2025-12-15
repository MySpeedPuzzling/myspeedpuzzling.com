import { Controller } from '@hotwired/stimulus';

/**
 * Native Billing Controller
 *
 * Handles in-app purchases for iOS (App Store) and Android (Play Store).
 * This controller communicates with native app code via JavaScript bridges.
 */
export default class extends Controller {
    static values = {
        platform: String // 'ios' or 'android'
    };

    connect() {
        // Set up callbacks for native purchase results
        window.onNativePurchaseSuccess = this.handlePurchaseSuccess.bind(this);
        window.onNativePurchaseError = this.handlePurchaseError.bind(this);
        window.onNativePurchaseCancelled = this.handlePurchaseCancelled.bind(this);
    }

    disconnect() {
        // Clean up global callbacks
        delete window.onNativePurchaseSuccess;
        delete window.onNativePurchaseError;
        delete window.onNativePurchaseCancelled;
    }

    purchase(event) {
        const productId = event.params.product;

        if (!productId) {
            console.error('No product ID specified');
            return;
        }

        if (this.platformValue === 'ios') {
            this.purchaseIos(productId);
        } else if (this.platformValue === 'android') {
            this.purchaseAndroid(productId);
        } else {
            console.error('Unknown platform:', this.platformValue);
        }
    }

    purchaseIos(productId) {
        // Check if iOS native bridge is available
        if (window.webkit && window.webkit.messageHandlers && window.webkit.messageHandlers.billing) {
            window.webkit.messageHandlers.billing.postMessage({
                action: 'purchase',
                productId: productId
            });
        } else {
            console.error('iOS billing bridge not available');
            alert('Please update the app to enable purchases.');
        }
    }

    purchaseAndroid(productId) {
        // Check if Android native bridge is available
        if (window.AndroidBilling && typeof window.AndroidBilling.purchase === 'function') {
            window.AndroidBilling.purchase(productId);
        } else {
            console.error('Android billing bridge not available');
            alert('Please update the app to enable purchases.');
        }
    }

    handlePurchaseSuccess(purchaseData) {
        // purchaseData contains receipt/token information
        // Send to server for verification
        this.verifyPurchase(purchaseData);
    }

    handlePurchaseError(errorMessage) {
        console.error('Purchase error:', errorMessage);
        alert('Purchase failed: ' + errorMessage);
    }

    handlePurchaseCancelled() {
        // User cancelled the purchase - no action needed
        console.log('Purchase cancelled by user');
    }

    async verifyPurchase(purchaseData) {
        const endpoint = this.platformValue === 'ios'
            ? '/api/ios/verify-receipt'
            : '/api/android/verify-purchase';

        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(purchaseData)
            });

            if (response.ok) {
                // Reload the page to show updated membership status
                window.location.reload();
            } else {
                const error = await response.json();
                alert('Verification failed: ' + (error.message || 'Unknown error'));
            }
        } catch (error) {
            console.error('Verification request failed:', error);
            alert('Could not verify purchase. Please try again later.');
        }
    }
}
