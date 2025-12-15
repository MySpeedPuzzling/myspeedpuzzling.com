# Add project specific ProGuard rules here.
# You can control the set of applied configuration files using the
# proguardFiles setting in build.gradle.kts.

# Keep JavaScript interface methods
-keepclassmembers class com.myspeedpuzzling.features.BarcodeScannerBridge {
    @android.webkit.JavascriptInterface <methods>;
}

-keepclassmembers class com.myspeedpuzzling.billing.BillingBridge {
    @android.webkit.JavascriptInterface <methods>;
}

# Keep Hotwire Turbo classes
-keep class dev.hotwire.turbo.** { *; }

# Keep Google Play Billing classes
-keep class com.android.vending.billing.** { *; }

# OkHttp
-dontwarn okhttp3.**
-dontwarn okio.**
-keep class okhttp3.** { *; }
-keep interface okhttp3.** { *; }

# ML Kit
-keep class com.google.mlkit.** { *; }
