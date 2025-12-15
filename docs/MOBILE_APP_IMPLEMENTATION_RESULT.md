# Mobile App Implementation Summary

## Overview

Implemented iOS and Android mobile apps using **Hotwire Native** as hybrid wrappers around the existing Symfony web app. The apps reuse 90%+ of the web codebase while having platform-specific billing (App Store for iOS, Play Store for Android, Stripe for web).

## What Was Implemented

### Phase 1: Platform Detection
- **Platform enum** (`src/Value/Platform.php`) - Web/iOS/Android detection
- **PlatformDetector service** - Detects platform from User-Agent header
- **PlatformTwigExtension** - Twig functions `is_web()`, `is_ios()`, `is_android()`, `is_native_app()`
- **JavaScript detection** in `app.js` - Sets `window.isNativeApp` and `window.nativePlatform`
- **CSS classes** - `platform-web`, `platform-ios`, `platform-android`, `native-app`

### Phase 2: Billing Infrastructure
- **Database migration** - Added `platform` column to `membership` table
- **Membership entity** - Added platform property and helper methods
- **Billing interface** (`PlatformBillingInterface`) - Common contract for all platforms
- **Platform billing services** - `WebStripeBilling`, `IosAppStoreBilling`, `AndroidPlayBilling`
- **BillingFactory** - Returns appropriate service based on detected platform

### Phase 3: Platform-Specific UI
- **base.html.twig** - Header/footer wrapped in `{% if is_web() %}` conditionals
- **membership.html.twig** - Platform-specific subscription buttons and management links
- **Translations** - Added keys for all 6 languages (en, cs, de, es, fr, ja)

### Phase 4: Native Scanner Bridge
- **barcode_scanner_controller.js** - Added native bridge methods
  - `window.onNativeScanResult(code)` callback
  - `window.onNativeScanCancelled()` callback
  - Auto-detects native app and uses native scanner instead of web camera

### Phase 5: API Endpoints
- `POST /api/ios/verify-receipt` - iOS App Store receipt verification
- `POST /api/android/verify-purchase` - Android Play Store purchase verification

### Phase 6: iOS App (`ios/`)
- Swift Package Manager project with Hotwire Native iOS dependency
- `WebViewController` with JavaScript bridges for scanner and billing
- `BarcodeScannerBridge` - Native AVFoundation barcode scanner (EAN-8/EAN-13)
- `StoreKitManager` - StoreKit 2 in-app purchases
- `BillingBridge` - JavaScript bridge for purchases

### Phase 7: Android App (`android/`)
- Gradle project with Hotwire Turbo Android dependency
- `TurboWebFragment` with JavaScript interfaces
- `BarcodeScannerBridge` - CameraX + ML Kit barcode scanner
- `BillingManager` - Google Play Billing Library integration
- Material Design UI with scan overlay

### Phase 8: CI/CD
- `.github/workflows/ios.yml` - Builds on macOS, runs tests
- `.github/workflows/android.yml` - Builds APK, uploads artifacts

## Problems Faced & Solutions

### 1. PHPStan Error with `is_array()` Check
**Problem:** PHPStan complained that `is_array()` always evaluates to true when a `@var` annotation was placed before `json_decode()`.

**Solution:** Moved the `@var` annotation to after the `is_array()` check:
```php
// Before (error)
/** @var array<string, mixed> $data */
$data = json_decode($content, true);

// After (fixed)
$data = json_decode($content, true);
if (!is_array($data)) { return error; }
/** @var array<string, mixed> $data */
```

### 2. Duplicate Companion Object in Kotlin
**Problem:** Android `BarcodeScannerBridge.kt` had two `companion object` declarations.

**Solution:** Consolidated all constants into a single companion object at the bottom of the class.

### 3. Turbo Globally Disabled
**Problem:** Turbo is disabled via `data-turbo="false"` on `<html>` element.

**Solution:** This is intentional per project architecture. Native apps use Hotwire Native which handles navigation natively, so Turbo being disabled doesn't affect mobile apps.

## File Structure

```
ios/
├── Package.swift
├── .gitignore
└── MySpeedPuzzling/
    ├── App/MySpeedPuzzlingApp.swift
    ├── Navigation/MainNavigationView.swift
    ├── Web/WebViewController.swift
    ├── Features/BarcodeScannerBridge.swift
    └── Billing/
        ├── StoreKitManager.swift
        └── BillingBridge.swift

android/
├── build.gradle.kts
├── settings.gradle.kts
├── gradlew
└── app/
    └── src/main/
        ├── AndroidManifest.xml
        ├── java/com/myspeedpuzzling/
        │   ├── app/MainActivity.kt
        │   ├── app/TurboWebFragment.kt
        │   ├── features/BarcodeScannerBridge.kt
        │   ├── features/BarcodeScannerActivity.kt
        │   └── billing/
        │       ├── BillingBridge.kt
        │       └── BillingManager.kt
        └── res/
```

## Next Steps

### Immediate (Required for Launch)
1. **Run migration:** `docker compose exec web php bin/console doctrine:migrations:migrate`
2. **Implement actual receipt verification** in `IosAppStoreBilling` and `AndroidPlayBilling` (currently stubs)
3. **Set up App Store Connect:** Create app, configure in-app purchase products
4. **Set up Google Play Console:** Create app, configure subscription products

### For Production Deployment
5. **Code signing:**
   - iOS: Add certificates and provisioning profiles to GitHub secrets
   - Android: Create and secure signing keystore
6. **App icons and launch screens** for both platforms
7. **Webhook endpoints** for subscription status updates from Apple/Google
8. **TestFlight/Internal testing** before public release

### Product IDs
- **iOS:** `com.myspeedpuzzling.premium.monthly`, `com.myspeedpuzzling.premium.yearly`
- **Android:** `premium_monthly`, `premium_yearly`

## Testing

The web app continues to work normally. All PHP checks pass:
- PHPStan: ✅
- PHPCS: ✅
- PHPUnit: ✅
- Schema validation: ✅
- Cache warmup: ✅

Native apps are isolated in `ios/` and `android/` directories and cannot break the web app.
