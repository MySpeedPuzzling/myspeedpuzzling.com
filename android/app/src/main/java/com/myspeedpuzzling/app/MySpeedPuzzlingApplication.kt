package com.myspeedpuzzling.app

import android.app.Application
import dev.hotwire.core.config.Hotwire
import dev.hotwire.navigation.config.defaultFragmentDestination
import dev.hotwire.navigation.config.registerFragmentDestinations
import dev.hotwire.navigation.fragments.HotwireWebFragment

class MySpeedPuzzlingApplication : Application() {

    override fun onCreate() {
        super.onCreate()

        // Configure Hotwire
        Hotwire.config.debugLoggingEnabled = BuildConfig.DEBUG
        Hotwire.config.webViewDebuggingEnabled = BuildConfig.DEBUG

        // Set custom user agent
        Hotwire.config.applicationUserAgentPrefix = "MySpeedPuzzling Android/1.0;"

        // Register our custom WebFragment as default destination
        Hotwire.defaultFragmentDestination = SpeedPuzzlingWebFragment::class
        Hotwire.registerFragmentDestinations(
            SpeedPuzzlingWebFragment::class,
            HotwireWebFragment::class
        )
    }
}
