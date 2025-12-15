# Mobile App Implementation Guide

## Overview

This document provides step-by-step instructions for implementing iOS and Android mobile apps using **Hotwire Native** with a **monorepo** structure.

**Architecture:** Hotwire Native wraps the existing web app in native shells, reusing 90%+ of the codebase.

**Billing Strategy:**
- Web: Stripe (existing)
- iOS: App Store In-App Purchase
- Android: Google Play Billing

---

## Critical Rules

1. **NEVER break the existing web app** - all changes must be backwards-compatible
2. **Run checks after every PHP change:**
   ```bash
   docker compose exec web composer run phpstan
   docker compose exec web composer run cs-fix
   docker compose exec web vendor/bin/phpunit
   docker compose exec web php bin/console doctrine:schema:validate
   docker compose exec web php bin/console cache:warmup
   ```
3. **Never delete existing code** - use conditionals to hide/show
4. **Never run migrations** - only create them, ask user to run
5. **iOS/Android directories are isolated** - changes there cannot break web
6. **Test web app manually** after completing each phase

---

## Repository Structure (Target)

```
myspeedpuzzling.com/
├── src/                          # Existing Symfony backend
├── templates/                    # Existing Twig templates
├── assets/                       # Existing JS/CSS
├── ios/                          # NEW: iOS native wrapper
│   └── MySpeedPuzzling/
├── android/                      # NEW: Android native wrapper
│   └── app/
├── docs/
│   └── MOBILE_APP_IMPLEMENTATION.md  # This file
└── ...existing files
```

---

## Phase 1: Platform Detection

**Goal:** Detect which platform (web/iOS/Android) is accessing the app.

### Task 1.1: Create Platform Enum

Create `src/Enum/Platform.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

enum Platform: string
{
    case Web = 'web';
    case Ios = 'ios';
    case Android = 'android';

    public function isNativeApp(): bool
    {
        return $this !== self::Web;
    }

    public function label(): string
    {
        return match($this) {
            self::Web => 'Web',
            self::Ios => 'iOS App',
            self::Android => 'Android App',
        };
    }
}
```

**Verify:** Run all checks.

---

### Task 1.2: Create Platform Detector Service

Create `src/Service/PlatformDetector.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\Platform;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class PlatformDetector
{
    public function __construct(
        private RequestStack $requestStack,
    ) {
    }

    public function detect(): Platform
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request === null) {
            return Platform::Web;
        }

        $userAgent = $request->headers->get('User-Agent', '');

        // Hotwire Native iOS sends: Turbo Native iOS
        if (str_contains($userAgent, 'Turbo Native iOS') || str_contains($userAgent, 'MySpeedPuzzling iOS')) {
            return Platform::Ios;
        }

        // Hotwire Native Android sends: Turbo Native Android
        if (str_contains($userAgent, 'Turbo Native Android') || str_contains($userAgent, 'MySpeedPuzzling Android')) {
            return Platform::Android;
        }

        return Platform::Web;
    }

    public function isNativeApp(): bool
    {
        return $this->detect()->isNativeApp();
    }

    public function isWeb(): bool
    {
        return $this->detect() === Platform::Web;
    }

    public function isIos(): bool
    {
        return $this->detect() === Platform::Ios;
    }

    public function isAndroid(): bool
    {
        return $this->detect() === Platform::Android;
    }
}
```

**Verify:** Run all checks.

---

### Task 1.3: Create Twig Extension for Platform Detection

Create `src/Twig/Extension/PlatformExtension.php`:

```php
<?php

declare(strict_types=1);

namespace App\Twig\Extension;

use App\Enum\Platform;
use App\Service\PlatformDetector;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

final class PlatformExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly PlatformDetector $platformDetector,
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'platform' => $this->platformDetector->detect(),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('is_native_app', [$this->platformDetector, 'isNativeApp']),
            new TwigFunction('is_web', [$this->platformDetector, 'isWeb']),
            new TwigFunction('is_ios', [$this->platformDetector, 'isIos']),
            new TwigFunction('is_android', [$this->platformDetector, 'isAndroid']),
        ];
    }
}
```

**Verify:** Run all checks.

---

### Task 1.4: Add Platform Detection JavaScript

Edit `assets/app.js` - add at the top (do not remove existing code):

```javascript
// Platform detection for native apps
(function() {
    const userAgent = navigator.userAgent || '';

    if (userAgent.includes('Turbo Native iOS') || userAgent.includes('MySpeedPuzzling iOS')) {
        window.nativePlatform = 'ios';
    } else if (userAgent.includes('Turbo Native Android') || userAgent.includes('MySpeedPuzzling Android')) {
        window.nativePlatform = 'android';
    } else {
        window.nativePlatform = 'web';
    }

    window.isNativeApp = window.nativePlatform !== 'web';

    // Add class to body for CSS targeting
    document.documentElement.classList.add('platform-' + window.nativePlatform);
    if (window.isNativeApp) {
        document.documentElement.classList.add('native-app');
    }
})();
```

**Verify:** Run `npm run build`, check web app still works.

---

### Task 1.5: Add Platform-Specific CSS

Create `assets/styles/_native.scss` (or add to existing styles):

```scss
// Hide elements in native apps
.native-app {
    .web-only {
        display: none !important;
    }
}

// Hide elements on web
html:not(.native-app) {
    .native-only {
        display: none !important;
    }
}

// Platform-specific visibility
.platform-web .ios-only,
.platform-web .android-only,
.platform-ios .web-only,
.platform-ios .android-only,
.platform-android .web-only,
.platform-android .ios-only {
    display: none !important;
}
```

Import this file in main stylesheet.

**Verify:** Run `npm run build`, check web app still works.

---

## Phase 2: Billing Infrastructure

**Goal:** Create platform-aware billing system supporting Stripe (web), App Store (iOS), and Play Store (Android).

### Task 2.1: Create Doctrine Migration for Platform Column

Create migration (do NOT run it):

```bash
docker compose exec web php bin/console make:migration
```

Migration should add `platform` column to subscription/membership table:

```php
// In the generated migration - adjust table name as needed
public function up(Schema $schema): void
{
    $this->addSql("ALTER TABLE subscription ADD platform VARCHAR(10) DEFAULT 'web' NOT NULL");
    $this->addSql("COMMENT ON COLUMN subscription.platform IS 'Billing platform: web, ios, android'");
}

public function down(Schema $schema): void
{
    $this->addSql('ALTER TABLE subscription DROP platform');
}
```

**Important:** Do NOT run the migration. Just create it and inform user.

**Verify:** Run phpstan, cs-fix.

---

### Task 2.2: Update Subscription Entity

Find the subscription/membership entity and add:

```php
use App\Enum\Platform;

// Add property
#[ORM\Column(length: 10, options: ['default' => 'web'])]
private string $platform = 'web';

// Add getter
public function getPlatform(): Platform
{
    return Platform::from($this->platform);
}

// Add setter
public function setPlatform(Platform $platform): void
{
    $this->platform = $platform->value;
}

// Add helper methods
public function isManagedByAppStore(): bool
{
    return $this->getPlatform() === Platform::Ios;
}

public function isManagedByPlayStore(): bool
{
    return $this->getPlatform() === Platform::Android;
}

public function isManagedByStripe(): bool
{
    return $this->getPlatform() === Platform::Web;
}
```

**Verify:** Run all checks including `doctrine:schema:validate` (will show diff until migration runs).

---

### Task 2.3: Create Billing Interface

Create `src/Billing/PlatformBillingInterface.php`:

```php
<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Player;

interface PlatformBillingInterface
{
    /**
     * Get URL or data needed to initiate subscription
     * @return array{type: string, url?: string, productId?: string}
     */
    public function getSubscriptionInitiation(Player $player): array;

    /**
     * Get URL for managing existing subscription
     */
    public function getManagementUrl(Player $player): ?string;

    /**
     * Verify a purchase/receipt and activate subscription
     * @param array<string, mixed> $purchaseData
     */
    public function verifyAndActivate(Player $player, array $purchaseData): bool;

    /**
     * Cancel subscription
     */
    public function cancel(Player $player): bool;
}
```

**Verify:** Run all checks.

---

### Task 2.4: Create Web (Stripe) Billing Service

Create `src/Billing/WebStripeBilling.php`:

```php
<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Player;

final readonly class WebStripeBilling implements PlatformBillingInterface
{
    public function __construct(
        // Inject existing Stripe/membership services here
    ) {
    }

    public function getSubscriptionInitiation(Player $player): array
    {
        // Return existing Stripe checkout URL logic
        // Wrap your existing implementation
        return [
            'type' => 'redirect',
            'url' => '/membership/subscribe', // Your existing route
        ];
    }

    public function getManagementUrl(Player $player): ?string
    {
        // Return Stripe customer portal URL
        // Wrap your existing implementation
        return '/membership/manage'; // Your existing route
    }

    public function verifyAndActivate(Player $player, array $purchaseData): bool
    {
        // Stripe handles this via webhooks, not needed here
        return true;
    }

    public function cancel(Player $player): bool
    {
        // Wrap existing cancellation logic
        return true;
    }
}
```

**Note:** This wraps existing Stripe logic. Adjust to use your actual services.

**Verify:** Run all checks.

---

### Task 2.5: Create iOS Billing Service (Stub)

Create `src/Billing/IosAppStoreBilling.php`:

```php
<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Player;
use App\Enum\Platform;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class IosAppStoreBilling implements PlatformBillingInterface
{
    private const APP_STORE_SUBSCRIPTIONS_URL = 'https://apps.apple.com/account/subscriptions';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getSubscriptionInitiation(Player $player): array
    {
        // iOS app will handle this natively via StoreKit
        return [
            'type' => 'native',
            'productId' => 'com.myspeedpuzzling.premium.monthly', // Configure your product ID
        ];
    }

    public function getManagementUrl(Player $player): ?string
    {
        return self::APP_STORE_SUBSCRIPTIONS_URL;
    }

    public function verifyAndActivate(Player $player, array $purchaseData): bool
    {
        // TODO: Implement App Store receipt verification
        // This will be called from IosReceiptVerificationController

        $this->logger->info('iOS purchase verification requested', [
            'player_id' => $player->getId(),
            'purchase_data' => $purchaseData,
        ]);

        // Placeholder - implement actual verification
        return false;
    }

    public function cancel(Player $player): bool
    {
        // iOS subscriptions are managed via App Store
        // We just update our records when Apple notifies us
        return true;
    }
}
```

**Verify:** Run all checks.

---

### Task 2.6: Create Android Billing Service (Stub)

Create `src/Billing/AndroidPlayBilling.php`:

```php
<?php

declare(strict_types=1);

namespace App\Billing;

use App\Entity\Player;
use App\Enum\Platform;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class AndroidPlayBilling implements PlatformBillingInterface
{
    private const PLAY_STORE_SUBSCRIPTIONS_URL = 'https://play.google.com/store/account/subscriptions';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function getSubscriptionInitiation(Player $player): array
    {
        // Android app will handle this natively via Google Play Billing
        return [
            'type' => 'native',
            'productId' => 'premium_monthly', // Configure your product ID
        ];
    }

    public function getManagementUrl(Player $player): ?string
    {
        return self::PLAY_STORE_SUBSCRIPTIONS_URL;
    }

    public function verifyAndActivate(Player $player, array $purchaseData): bool
    {
        // TODO: Implement Google Play purchase verification
        // This will be called from AndroidPurchaseVerificationController

        $this->logger->info('Android purchase verification requested', [
            'player_id' => $player->getId(),
            'purchase_data' => $purchaseData,
        ]);

        // Placeholder - implement actual verification
        return false;
    }

    public function cancel(Player $player): bool
    {
        // Android subscriptions are managed via Play Store
        // We just update our records when Google notifies us
        return true;
    }
}
```

**Verify:** Run all checks.

---

### Task 2.7: Create Billing Factory

Create `src/Billing/BillingFactory.php`:

```php
<?php

declare(strict_types=1);

namespace App\Billing;

use App\Enum\Platform;
use App\Service\PlatformDetector;

final readonly class BillingFactory
{
    public function __construct(
        private PlatformDetector $platformDetector,
        private WebStripeBilling $webBilling,
        private IosAppStoreBilling $iosBilling,
        private AndroidPlayBilling $androidBilling,
    ) {
    }

    public function getBillingService(?Platform $platform = null): PlatformBillingInterface
    {
        $platform ??= $this->platformDetector->detect();

        return match ($platform) {
            Platform::Web => $this->webBilling,
            Platform::Ios => $this->iosBilling,
            Platform::Android => $this->androidBilling,
        };
    }

    public function getForCurrentPlatform(): PlatformBillingInterface
    {
        return $this->getBillingService();
    }
}
```

**Verify:** Run all checks.

---

## Phase 3: Platform-Specific UI

**Goal:** Conditionally show/hide UI elements based on platform.

### Task 3.1: Update Base Layout for Native Apps

Edit `templates/base.html.twig` - wrap navigation in conditional:

```twig
{# Keep existing navigation but wrap in conditional #}
{% if is_web() %}
    {# Your existing header/navigation here #}
    <header>
        ...existing header code...
    </header>
{% endif %}

{# Main content stays the same #}
<main>
    {% block body %}{% endblock %}
</main>

{# Keep existing footer but wrap in conditional #}
{% if is_web() %}
    <footer>
        ...existing footer code...
    </footer>
{% endif %}
```

**Important:** Do not delete any existing code, only wrap in conditionals.

**Verify:** Check web app still displays header/footer correctly.

---

### Task 3.2: Create Subscription Page Platform Variants

Find existing subscription/membership template and add platform conditionals:

```twig
{% if is_web() %}
    {# Existing Stripe subscription UI #}
    <a href="{{ path('membership_subscribe') }}" class="btn btn-primary">
        Subscribe with Card
    </a>
{% elseif is_ios() %}
    {# iOS App Store subscription #}
    <button
        type="button"
        class="btn btn-primary"
        data-controller="native-purchase"
        data-native-purchase-product-value="com.myspeedpuzzling.premium.monthly"
    >
        Subscribe via App Store
    </button>
{% elseif is_android() %}
    {# Android Play Store subscription #}
    <button
        type="button"
        class="btn btn-primary"
        data-controller="native-purchase"
        data-native-purchase-product-value="premium_monthly"
    >
        Subscribe via Google Play
    </button>
{% endif %}
```

**Verify:** Check web app subscription page still works.

---

### Task 3.3: Update Subscription Management Display

Find template showing subscription status and add:

```twig
{% if player.subscription %}
    <p>Your subscription is managed via {{ player.subscription.platform.label }}.</p>

    {% set management_url = billing_management_url(player) %}
    {% if management_url %}
        <a href="{{ management_url }}" class="btn btn-secondary" {% if not is_web() %}target="_blank"{% endif %}>
            Manage Subscription
        </a>
    {% endif %}

    {% if player.subscription.isManagedByAppStore %}
        <p class="text-muted small">
            To cancel, open Settings > Apple ID > Subscriptions on your iPhone.
        </p>
    {% elseif player.subscription.isManagedByPlayStore %}
        <p class="text-muted small">
            To cancel, open Google Play Store > Account > Subscriptions.
        </p>
    {% endif %}
{% endif %}
```

**Verify:** Run all checks.

---

### Task 3.4: Create Twig Function for Billing Management URL

Add to `src/Twig/Extension/PlatformExtension.php`:

```php
// Add to constructor
private readonly BillingFactory $billingFactory,

// Add to getFunctions()
new TwigFunction('billing_management_url', [$this, 'getBillingManagementUrl']),

// Add method
public function getBillingManagementUrl(Player $player): ?string
{
    $platform = $player->getSubscription()?->getPlatform() ?? $this->platformDetector->detect();
    return $this->billingFactory->getBillingService($platform)->getManagementUrl($player);
}
```

**Verify:** Run all checks.

---

## Phase 4: Native Scanner Bridge

**Goal:** Allow native apps to use their native camera scanner, bridging result back to web.

### Task 4.1: Update Barcode Scanner Controller

Edit `assets/controllers/barcode_scanner_controller.js`:

Add at the beginning of the `initCamera()` method:

```javascript
initCamera() {
    // Check if running in native app with native scanner
    if (window.isNativeApp && window.NativeScanner) {
        this.useNativeScanner();
        return;
    }

    // ... existing web camera code continues below ...
    this.wrapperTarget.classList.remove('d-none');
    // ... rest of existing code ...
}
```

Add new method:

```javascript
useNativeScanner() {
    // Request native app to open scanner
    if (window.webkit?.messageHandlers?.scanner) {
        // iOS
        window.webkit.messageHandlers.scanner.postMessage({ action: 'open' });
    } else if (window.AndroidScanner) {
        // Android
        window.AndroidScanner.openScanner();
    }

    // Native app will call window.onNativeScanResult(code) when done
}
```

Add at the end of `connect()` method:

```javascript
connect() {
    // ... existing code ...

    // Listen for native scanner results
    window.onNativeScanResult = (code) => {
        if (code && this.hasInputTarget) {
            this.inputTarget.value = code;
            this.inputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
    };

    window.onNativeScanCancelled = () => {
        // Scanner was closed without result
        this.stopScanning();
    };
}
```

**Verify:** Run `npm run build`, test web scanner still works.

---

## Phase 5: API Endpoints for Native Purchases

**Goal:** Create API endpoints for iOS/Android apps to verify purchases.

### Task 5.1: Create iOS Receipt Verification Controller

Create `src/Controller/Api/IosReceiptVerificationController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Billing\IosAppStoreBilling;
use App\Repository\PlayerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/ios/verify-receipt', name: 'api_ios_verify_receipt', methods: ['POST'])]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class IosReceiptVerificationController extends AbstractController
{
    public function __construct(
        private readonly IosAppStoreBilling $iosBilling,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $player = $this->getUser();

        if (!$player instanceof Player) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['receiptData'])) {
            return new JsonResponse(['error' => 'Missing receiptData'], Response::HTTP_BAD_REQUEST);
        }

        $success = $this->iosBilling->verifyAndActivate($player, $data);

        return new JsonResponse([
            'success' => $success,
            'message' => $success ? 'Subscription activated' : 'Verification failed',
        ]);
    }
}
```

**Verify:** Run all checks.

---

### Task 5.2: Create Android Purchase Verification Controller

Create `src/Controller/Api/AndroidPurchaseVerificationController.php`:

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Billing\AndroidPlayBilling;
use App\Repository\PlayerRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/android/verify-purchase', name: 'api_android_verify_purchase', methods: ['POST'])]
#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class AndroidPurchaseVerificationController extends AbstractController
{
    public function __construct(
        private readonly AndroidPlayBilling $androidBilling,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $player = $this->getUser();

        if (!$player instanceof Player) {
            return new JsonResponse(['error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['purchaseToken']) || !isset($data['productId'])) {
            return new JsonResponse(['error' => 'Missing purchaseToken or productId'], Response::HTTP_BAD_REQUEST);
        }

        $success = $this->androidBilling->verifyAndActivate($player, $data);

        return new JsonResponse([
            'success' => $success,
            'message' => $success ? 'Subscription activated' : 'Verification failed',
        ]);
    }
}
```

**Verify:** Run all checks.

---

## Phase 6: iOS App (Native Code)

**Goal:** Create iOS native wrapper using Hotwire Native.

### Task 6.1: Create iOS Project Directory Structure

```bash
mkdir -p ios/MySpeedPuzzling/App
mkdir -p ios/MySpeedPuzzling/Navigation
mkdir -p ios/MySpeedPuzzling/Features
mkdir -p ios/MySpeedPuzzling/Billing
mkdir -p ios/MySpeedPuzzling/Resources
```

Create `ios/.gitignore`:

```gitignore
# Xcode
build/
DerivedData/
*.xcworkspace
!*.xcworkspace/contents.xcworkspacedata
xcuserdata/

# CocoaPods
Pods/

# Swift Package Manager
.build/
.swiftpm/

# Fastlane
fastlane/report.xml
fastlane/Preview.html
fastlane/screenshots/
fastlane/test_output/
```

---

### Task 6.2: Create iOS Package.swift

Create `ios/Package.swift`:

```swift
// swift-tools-version:5.9
import PackageDescription

let package = Package(
    name: "MySpeedPuzzling",
    platforms: [.iOS(.v15)],
    dependencies: [
        .package(url: "https://github.com/hotwired/hotwire-native-ios", from: "1.0.0")
    ],
    targets: [
        .target(
            name: "MySpeedPuzzling",
            dependencies: [
                .product(name: "HotwireNative", package: "hotwire-native-ios")
            ]
        )
    ]
)
```

---

### Task 6.3: Create iOS App Entry Point

Create `ios/MySpeedPuzzling/App/MySpeedPuzzlingApp.swift`:

```swift
import SwiftUI
import HotwireNative

@main
struct MySpeedPuzzlingApp: App {
    init() {
        Hotwire.config.userAgent = "MySpeedPuzzling iOS; Turbo Native iOS"
        Hotwire.config.defaultNavigationMode = .advance
    }

    var body: some Scene {
        WindowGroup {
            MainNavigationView()
        }
    }
}
```

---

### Task 6.4: Create iOS Main Navigation

Create `ios/MySpeedPuzzling/Navigation/MainNavigationView.swift`:

```swift
import SwiftUI
import HotwireNative

struct MainNavigationView: View {
    @State private var navigator = Navigator()

    private let baseURL = URL(string: "https://www.myspeedpuzzling.com")! // Change to your URL

    var body: some View {
        NavigationStack(path: $navigator.path) {
            HotwireWebView(url: baseURL, navigator: navigator)
                .navigationDestination(for: URL.self) { url in
                    HotwireWebView(url: url, navigator: navigator)
                }
        }
        .environment(navigator)
    }
}
```

---

### Task 6.5: Create iOS Barcode Scanner Bridge

Create `ios/MySpeedPuzzling/Features/BarcodeScannerBridge.swift`:

```swift
import AVFoundation
import UIKit
import WebKit

class BarcodeScannerBridge: NSObject, WKScriptMessageHandler {
    weak var webView: WKWebView?
    private var scannerViewController: BarcodeScannerViewController?

    func userContentController(_ userContentController: WKUserContentController, didReceive message: WKScriptMessage) {
        guard message.name == "scanner" else { return }

        if let body = message.body as? [String: Any], let action = body["action"] as? String {
            if action == "open" {
                openScanner()
            }
        }
    }

    private func openScanner() {
        guard let windowScene = UIApplication.shared.connectedScenes.first as? UIWindowScene,
              let rootVC = windowScene.windows.first?.rootViewController else { return }

        let scanner = BarcodeScannerViewController()
        scanner.delegate = self
        scanner.modalPresentationStyle = .fullScreen
        rootVC.present(scanner, animated: true)
        self.scannerViewController = scanner
    }

    func didScanBarcode(_ code: String) {
        scannerViewController?.dismiss(animated: true)
        webView?.evaluateJavaScript("window.onNativeScanResult('\(code)')")
    }

    func didCancelScanning() {
        scannerViewController?.dismiss(animated: true)
        webView?.evaluateJavaScript("window.onNativeScanCancelled()")
    }
}

// Basic scanner view controller - expand as needed
class BarcodeScannerViewController: UIViewController, AVCaptureMetadataOutputObjectsDelegate {
    weak var delegate: BarcodeScannerBridge?
    private var captureSession: AVCaptureSession?

    override func viewDidLoad() {
        super.viewDidLoad()
        setupCamera()
    }

    private func setupCamera() {
        let session = AVCaptureSession()

        guard let device = AVCaptureDevice.default(for: .video),
              let input = try? AVCaptureDeviceInput(device: device) else { return }

        session.addInput(input)

        let output = AVCaptureMetadataOutput()
        session.addOutput(output)
        output.setMetadataObjectsDelegate(self, queue: .main)
        output.metadataObjectTypes = [.ean8, .ean13]

        let preview = AVCaptureVideoPreviewLayer(session: session)
        preview.frame = view.bounds
        preview.videoGravity = .resizeAspectFill
        view.layer.addSublayer(preview)

        self.captureSession = session

        DispatchQueue.global(qos: .userInitiated).async {
            session.startRunning()
        }
    }

    func metadataOutput(_ output: AVCaptureMetadataOutput, didOutput metadataObjects: [AVMetadataObject], from connection: AVCaptureConnection) {
        if let object = metadataObjects.first as? AVMetadataMachineReadableCodeObject,
           let code = object.stringValue {
            captureSession?.stopRunning()
            delegate?.didScanBarcode(code)
        }
    }
}
```

---

### Task 6.6: Create iOS StoreKit Manager (Stub)

Create `ios/MySpeedPuzzling/Billing/StoreKitManager.swift`:

```swift
import StoreKit

@MainActor
class StoreKitManager: ObservableObject {
    static let shared = StoreKitManager()

    @Published var products: [Product] = []
    @Published var purchasedProductIDs: Set<String> = []

    private let productIDs = ["com.myspeedpuzzling.premium.monthly"]

    init() {
        Task {
            await loadProducts()
            await updatePurchasedProducts()
        }
    }

    func loadProducts() async {
        do {
            products = try await Product.products(for: productIDs)
        } catch {
            print("Failed to load products: \(error)")
        }
    }

    func purchase(_ product: Product) async throws -> Bool {
        let result = try await product.purchase()

        switch result {
        case .success(let verification):
            let transaction = try checkVerified(verification)

            // Send receipt to your server for verification
            await sendReceiptToServer(transaction)

            await transaction.finish()
            return true

        case .pending, .userCancelled:
            return false

        @unknown default:
            return false
        }
    }

    private func checkVerified<T>(_ result: VerificationResult<T>) throws -> T {
        switch result {
        case .verified(let safe):
            return safe
        case .unverified:
            throw StoreError.verificationFailed
        }
    }

    private func sendReceiptToServer(_ transaction: Transaction) async {
        // TODO: Implement server verification
        // POST to /api/ios/verify-receipt with receipt data
    }

    func updatePurchasedProducts() async {
        for await result in Transaction.currentEntitlements {
            if case .verified(let transaction) = result {
                purchasedProductIDs.insert(transaction.productID)
            }
        }
    }
}

enum StoreError: Error {
    case verificationFailed
}
```

---

## Phase 7: Android App (Native Code)

**Goal:** Create Android native wrapper using Hotwire Native.

### Task 7.1: Create Android Project Directory Structure

```bash
mkdir -p android/app/src/main/java/com/myspeedpuzzling/app
mkdir -p android/app/src/main/java/com/myspeedpuzzling/navigation
mkdir -p android/app/src/main/java/com/myspeedpuzzling/features
mkdir -p android/app/src/main/java/com/myspeedpuzzling/billing
mkdir -p android/app/src/main/res/layout
mkdir -p android/app/src/main/res/values
```

Create `android/.gitignore`:

```gitignore
# Gradle
.gradle/
build/

# Android Studio
.idea/
*.iml
local.properties

# Generated
app/release/
*.apk
*.aab

# Signing
*.jks
*.keystore
```

---

### Task 7.2: Create Android build.gradle (Project)

Create `android/build.gradle`:

```groovy
buildscript {
    ext.kotlin_version = '1.9.22'
    repositories {
        google()
        mavenCentral()
    }
    dependencies {
        classpath 'com.android.tools.build:gradle:8.2.2'
        classpath "org.jetbrains.kotlin:kotlin-gradle-plugin:$kotlin_version"
    }
}

allprojects {
    repositories {
        google()
        mavenCentral()
    }
}
```

---

### Task 7.3: Create Android build.gradle (App)

Create `android/app/build.gradle`:

```groovy
plugins {
    id 'com.android.application'
    id 'org.jetbrains.kotlin.android'
}

android {
    namespace 'com.myspeedpuzzling.app'
    compileSdk 34

    defaultConfig {
        applicationId "com.myspeedpuzzling.app"
        minSdk 24
        targetSdk 34
        versionCode 1
        versionName "1.0.0"
    }

    buildTypes {
        release {
            minifyEnabled true
            proguardFiles getDefaultProguardFile('proguard-android-optimize.txt'), 'proguard-rules.pro'
        }
    }

    compileOptions {
        sourceCompatibility JavaVersion.VERSION_17
        targetCompatibility JavaVersion.VERSION_17
    }

    kotlinOptions {
        jvmTarget = '17'
    }
}

dependencies {
    implementation 'dev.hotwire:turbo:7.1.0'
    implementation 'androidx.appcompat:appcompat:1.6.1'
    implementation 'com.google.android.material:material:1.11.0'

    // ML Kit for barcode scanning
    implementation 'com.google.mlkit:barcode-scanning:17.2.0'

    // Google Play Billing
    implementation 'com.android.billingclient:billing-ktx:6.1.0'

    // Camera
    implementation 'androidx.camera:camera-camera2:1.3.1'
    implementation 'androidx.camera:camera-lifecycle:1.3.1'
    implementation 'androidx.camera:camera-view:1.3.1'
}
```

---

### Task 7.4: Create Android MainActivity

Create `android/app/src/main/java/com/myspeedpuzzling/app/MainActivity.kt`:

```kotlin
package com.myspeedpuzzling.app

import android.os.Bundle
import dev.hotwire.turbo.activities.TurboActivity
import dev.hotwire.turbo.delegates.TurboActivityDelegate

class MainActivity : TurboActivity() {
    override lateinit var delegate: TurboActivityDelegate

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)

        delegate = TurboActivityDelegate(this, R.id.main_nav_host)

        setContentView(R.layout.activity_main)
    }

    companion object {
        const val BASE_URL = "https://www.myspeedpuzzling.com" // Change to your URL
    }
}
```

---

### Task 7.5: Create Android Barcode Scanner

Create `android/app/src/main/java/com/myspeedpuzzling/features/BarcodeScanner.kt`:

```kotlin
package com.myspeedpuzzling.features

import android.webkit.JavascriptInterface
import android.webkit.WebView
import androidx.activity.result.ActivityResultLauncher
import androidx.appcompat.app.AppCompatActivity
import com.google.mlkit.vision.barcode.common.Barcode

class BarcodeScannerBridge(
    private val activity: AppCompatActivity,
    private val webView: WebView
) {
    private var scannerLauncher: ActivityResultLauncher<Unit>? = null

    @JavascriptInterface
    fun openScanner() {
        activity.runOnUiThread {
            // Launch scanner activity
            // Implementation depends on your camera setup
            // On result, call sendResultToWebView(code)
        }
    }

    fun sendResultToWebView(code: String) {
        activity.runOnUiThread {
            webView.evaluateJavascript("window.onNativeScanResult('$code')", null)
        }
    }

    fun sendCancellationToWebView() {
        activity.runOnUiThread {
            webView.evaluateJavascript("window.onNativeScanCancelled()", null)
        }
    }
}
```

---

### Task 7.6: Create Android Billing Manager (Stub)

Create `android/app/src/main/java/com/myspeedpuzzling/billing/BillingManager.kt`:

```kotlin
package com.myspeedpuzzling.billing

import android.app.Activity
import com.android.billingclient.api.*
import kotlinx.coroutines.flow.MutableStateFlow
import kotlinx.coroutines.flow.StateFlow

class BillingManager(private val activity: Activity) : PurchasesUpdatedListener {

    private val billingClient = BillingClient.newBuilder(activity)
        .setListener(this)
        .enablePendingPurchases()
        .build()

    private val _products = MutableStateFlow<List<ProductDetails>>(emptyList())
    val products: StateFlow<List<ProductDetails>> = _products

    init {
        connectToPlayStore()
    }

    private fun connectToPlayStore() {
        billingClient.startConnection(object : BillingClientStateListener {
            override fun onBillingSetupFinished(result: BillingResult) {
                if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                    queryProducts()
                }
            }

            override fun onBillingServiceDisconnected() {
                // Retry connection
            }
        })
    }

    private fun queryProducts() {
        val productList = listOf(
            QueryProductDetailsParams.Product.newBuilder()
                .setProductId("premium_monthly")
                .setProductType(BillingClient.ProductType.SUBS)
                .build()
        )

        val params = QueryProductDetailsParams.newBuilder()
            .setProductList(productList)
            .build()

        billingClient.queryProductDetailsAsync(params) { result, productDetailsList ->
            if (result.responseCode == BillingClient.BillingResponseCode.OK) {
                _products.value = productDetailsList
            }
        }
    }

    fun launchPurchaseFlow(productDetails: ProductDetails) {
        val offerToken = productDetails.subscriptionOfferDetails?.firstOrNull()?.offerToken ?: return

        val productDetailsParams = BillingFlowParams.ProductDetailsParams.newBuilder()
            .setProductDetails(productDetails)
            .setOfferToken(offerToken)
            .build()

        val billingFlowParams = BillingFlowParams.newBuilder()
            .setProductDetailsParamsList(listOf(productDetailsParams))
            .build()

        billingClient.launchBillingFlow(activity, billingFlowParams)
    }

    override fun onPurchasesUpdated(result: BillingResult, purchases: List<Purchase>?) {
        if (result.responseCode == BillingClient.BillingResponseCode.OK && purchases != null) {
            for (purchase in purchases) {
                handlePurchase(purchase)
            }
        }
    }

    private fun handlePurchase(purchase: Purchase) {
        if (purchase.purchaseState == Purchase.PurchaseState.PURCHASED) {
            // TODO: Send to server for verification
            // POST to /api/android/verify-purchase

            // Then acknowledge the purchase
            val acknowledgePurchaseParams = AcknowledgePurchaseParams.newBuilder()
                .setPurchaseToken(purchase.purchaseToken)
                .build()

            billingClient.acknowledgePurchase(acknowledgePurchaseParams) { }
        }
    }
}
```

---

## Phase 8: CI/CD Setup

### Task 8.1: Create GitHub Actions for iOS

Create `.github/workflows/ios.yml`:

```yaml
name: iOS Build

on:
  push:
    paths:
      - 'ios/**'
      - '.github/workflows/ios.yml'
  pull_request:
    paths:
      - 'ios/**'

jobs:
  build:
    runs-on: macos-latest

    steps:
      - uses: actions/checkout@v4

      - name: Select Xcode
        run: sudo xcode-select -switch /Applications/Xcode_15.2.app

      - name: Build
        working-directory: ios
        run: |
          xcodebuild build \
            -scheme MySpeedPuzzling \
            -sdk iphonesimulator \
            -destination 'platform=iOS Simulator,name=iPhone 15'
```

---

### Task 8.2: Create GitHub Actions for Android

Create `.github/workflows/android.yml`:

```yaml
name: Android Build

on:
  push:
    paths:
      - 'android/**'
      - '.github/workflows/android.yml'
  pull_request:
    paths:
      - 'android/**'

jobs:
  build:
    runs-on: ubuntu-latest

    steps:
      - uses: actions/checkout@v4

      - name: Set up JDK 17
        uses: actions/setup-java@v4
        with:
          java-version: '17'
          distribution: 'temurin'

      - name: Setup Gradle
        uses: gradle/gradle-build-action@v2

      - name: Build
        working-directory: android
        run: ./gradlew assembleDebug
```

---

## Execution Checklist

Complete phases in order. Check off each task:

### Phase 1: Platform Detection
- [ ] Task 1.1: Create Platform Enum
- [ ] Task 1.2: Create Platform Detector Service
- [ ] Task 1.3: Create Twig Extension
- [ ] Task 1.4: Add Platform Detection JavaScript
- [ ] Task 1.5: Add Platform-Specific CSS
- [ ] **Verify web app works normally**

### Phase 2: Billing Infrastructure
- [ ] Task 2.1: Create Migration (DO NOT RUN)
- [ ] Task 2.2: Update Subscription Entity
- [ ] Task 2.3: Create Billing Interface
- [ ] Task 2.4: Create Web Stripe Billing Service
- [ ] Task 2.5: Create iOS Billing Service (Stub)
- [ ] Task 2.6: Create Android Billing Service (Stub)
- [ ] Task 2.7: Create Billing Factory
- [ ] **Verify web app works normally**

### Phase 3: Platform-Specific UI
- [ ] Task 3.1: Update Base Layout
- [ ] Task 3.2: Create Subscription Page Variants
- [ ] Task 3.3: Update Subscription Management Display
- [ ] Task 3.4: Create Twig Billing Function
- [ ] **Verify web app works normally**

### Phase 4: Native Scanner Bridge
- [ ] Task 4.1: Update Barcode Scanner Controller
- [ ] **Verify web scanner still works**

### Phase 5: API Endpoints
- [ ] Task 5.1: Create iOS Receipt Verification Controller
- [ ] Task 5.2: Create Android Purchase Verification Controller
- [ ] **Verify web app works normally**

### Phase 6: iOS App
- [ ] Task 6.1: Create Directory Structure
- [ ] Task 6.2: Create Package.swift
- [ ] Task 6.3: Create App Entry Point
- [ ] Task 6.4: Create Main Navigation
- [ ] Task 6.5: Create Barcode Scanner Bridge
- [ ] Task 6.6: Create StoreKit Manager

### Phase 7: Android App
- [ ] Task 7.1: Create Directory Structure
- [ ] Task 7.2: Create Project build.gradle
- [ ] Task 7.3: Create App build.gradle
- [ ] Task 7.4: Create MainActivity
- [ ] Task 7.5: Create Barcode Scanner
- [ ] Task 7.6: Create Billing Manager

### Phase 8: CI/CD
- [ ] Task 8.1: Create iOS GitHub Action
- [ ] Task 8.2: Create Android GitHub Action

---

## Post-Implementation

After all phases complete:

1. **Run migration:** `docker compose exec web php bin/console doctrine:migrations:migrate`
2. **Configure App Store:** Create app, configure in-app purchases
3. **Configure Play Store:** Create app, configure subscriptions
4. **Implement actual receipt/purchase verification** in billing services
5. **Set up webhook endpoints** for subscription status updates
6. **Test full purchase flows** on both platforms
