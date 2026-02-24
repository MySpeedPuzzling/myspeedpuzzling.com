import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    async connect() {
        let gallery = document.querySelectorAll('.gallery');

        if (gallery.length) {
            const [
                { default: lightGallery },
                { default: lgFullscreen },
                { default: lgZoom },
            ] = await Promise.all([
                import('lightgallery'),
                import('lightgallery/plugins/fullscreen'),
                import('lightgallery/plugins/zoom'),
                import('lightgallery/css/lightgallery-bundle.min.css'),
            ]);

            for (let i = 0; i < gallery.length; i++) {

                const thumbnails = gallery[i].dataset.thumbnails ? true : false,
                    video = gallery[i].dataset.video ? true : false,
                    defaultPlugins = [lgZoom, lgFullscreen],
                    videoPlugin = video ? [lgVideo] : [],
                    thumbnailPlugin = thumbnails ? [lgThumbnail] : [],
                    plugins = [...defaultPlugins, ...videoPlugin, ...thumbnailPlugin]

                lightGallery(gallery[i], {
                    selector: '.gallery-item',
                    plugins: plugins,
                    licenseKey: 'D4194FDD-48924833-A54AECA3-D6F8E646',
                    download: false,
                    autoplayVideoOnSlide: true,
                    zoomFromOrigin: false,
                    youtubePlayerParams: {
                        modestbranding: 1,
                        showinfo: 0,
                        rel: 0
                    },
                    vimeoPlayerParams: {
                        byline: 0,
                        portrait: 0,
                        color: '6366f1'
                    }
                });
            }
        }
    }
}
