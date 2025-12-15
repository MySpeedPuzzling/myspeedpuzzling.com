import SwiftUI
import HotwireNative

struct MainNavigationView: View {
    @StateObject private var navigator = Navigator()

    private let baseURL = URL(string: "https://myspeedpuzzling.com")!

    var body: some View {
        NavigationStack(path: $navigator.path) {
            HotwireWebView(url: baseURL)
                .environmentObject(navigator)
                .navigationDestination(for: URL.self) { url in
                    HotwireWebView(url: url)
                        .environmentObject(navigator)
                }
        }
        .environmentObject(navigator)
    }
}

class Navigator: ObservableObject {
    @Published var path = NavigationPath()

    func push(_ url: URL) {
        path.append(url)
    }

    func pop() {
        if !path.isEmpty {
            path.removeLast()
        }
    }

    func popToRoot() {
        path.removeLast(path.count)
    }
}

struct HotwireWebView: UIViewControllerRepresentable {
    let url: URL
    @EnvironmentObject var navigator: Navigator

    func makeUIViewController(context: Context) -> WebViewController {
        let viewController = WebViewController(url: url)
        viewController.delegate = context.coordinator
        return viewController
    }

    func updateUIViewController(_ uiViewController: WebViewController, context: Context) {
        // Update if needed
    }

    func makeCoordinator() -> Coordinator {
        Coordinator(navigator: navigator)
    }

    class Coordinator: VisitableViewControllerDelegate {
        let navigator: Navigator

        init(navigator: Navigator) {
            self.navigator = navigator
        }

        func visitableDidRequestRefresh(_ visitable: Visitable) {
            visitable.visitableViewController?.reloadVisitable()
        }

        func visitableDidRequestReload(_ visitable: Visitable) {
            visitable.visitableViewController?.reloadVisitable()
        }
    }
}

#Preview {
    MainNavigationView()
}
