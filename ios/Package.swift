// swift-tools-version: 5.9

import PackageDescription

let package = Package(
    name: "MySpeedPuzzling",
    platforms: [
        .iOS(.v17)
    ],
    products: [
        .library(
            name: "MySpeedPuzzling",
            targets: ["MySpeedPuzzling"]),
    ],
    dependencies: [
        .package(url: "https://github.com/hotwired/hotwire-native-ios", from: "1.0.0")
    ],
    targets: [
        .target(
            name: "MySpeedPuzzling",
            dependencies: [
                .product(name: "HotwireNative", package: "hotwire-native-ios")
            ],
            path: "MySpeedPuzzling"
        ),
    ]
)
