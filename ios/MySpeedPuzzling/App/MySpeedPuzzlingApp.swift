import SwiftUI
import HotwireNative

@main
struct MySpeedPuzzlingApp: App {
    init() {
        // Configure Hotwire Native
        Hotwire.config.userAgent = "MySpeedPuzzling iOS/1.0"
        Hotwire.config.debugLoggingEnabled = true
    }

    var body: some Scene {
        WindowGroup {
            MainNavigationView()
        }
    }
}
