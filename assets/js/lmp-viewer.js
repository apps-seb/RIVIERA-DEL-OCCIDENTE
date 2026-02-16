// assets/js/lmp-viewer.js

(function() {
    window.initLMPViewer = function(config) {
        // Configuration
        const container = document.getElementById(config.containerId);
        if (!container) return;

        const imageUrl = config.imageUrl;
        const markersData = config.markers || [];

        // Scene Setup
        const scene = new THREE.Scene();
        scene.background = new THREE.Color(0x202020); // Dark grey background

        // Camera Setup
        const width = container.clientWidth;
        const height = container.clientHeight;
        const camera = new THREE.PerspectiveCamera(45, width / height, 1, 100000);

        // Renderer Setup (WebGL)
        const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
        renderer.setSize(width, height);
        renderer.setPixelRatio(window.devicePixelRatio);
        container.appendChild(renderer.domElement);

        // CSS2D Renderer Setup (For Labels)
        const labelRenderer = new THREE.CSS2DRenderer();
        labelRenderer.setSize(width, height);
        labelRenderer.domElement.style.position = 'absolute';
        labelRenderer.domElement.style.top = '0px';
        labelRenderer.domElement.style.pointerEvents = 'none'; // Allow clicks to pass through to orbit controls if needed? No, we need clicks on labels.
        // Actually, CSS2DRenderer overlays DOM elements. If they have pointer-events:auto, they catch clicks.
        // If the container has controls, we need to ensure controls don't block labels or vice-versa.
        // Usually, OrbitControls attaches to the labelRenderer.domElement if it's on top.
        container.appendChild(labelRenderer.domElement);

        // Orbit Controls
        const controls = new THREE.OrbitControls(camera, labelRenderer.domElement);
        controls.enableDamping = true;
        controls.dampingFactor = 0.05;
        controls.screenSpacePanning = false;
        controls.minDistance = 100;
        controls.maxDistance = 5000;
        controls.maxPolarAngle = Math.PI / 2; // Don't go below ground

        // Texture Loader
        const loader = new THREE.TextureLoader();
        loader.load(imageUrl, function(texture) {
            // Image Dimensions
            const imgWidth = texture.image.width;
            const imgHeight = texture.image.height;

            // Create Plane
            const geometry = new THREE.PlaneGeometry(imgWidth, imgHeight);
            const material = new THREE.MeshBasicMaterial({ map: texture, side: THREE.DoubleSide });
            const plane = new THREE.Mesh(geometry, material);
            scene.add(plane);

            // Center Camera
            // Fit camera to view the plane
            const fov = camera.fov * (Math.PI / 180);
            let cameraZ = Math.abs(imgHeight / 2 / Math.tan(fov / 2));
            cameraZ *= 1.5; // Zoom out a bit

            // Set initial position (Tilted View)
            camera.position.set(0, -imgHeight/2, cameraZ);
            camera.lookAt(0, 0, 0);
            controls.target.set(0, 0, 0);
            controls.update();

            // Add Markers
            addMarkers(imgWidth, imgHeight);

            // Start Animation Loop
            animate();
        });

        const markersObjects = []; // Store references for filtering

        function addMarkers(mapWidth, mapHeight) {
            markersData.forEach(function(m) {
                // Create DIV
                const div = document.createElement('div');
                div.className = 'lmp-marker-label status-' + m.status; // status-available, etc.
                div.textContent = m.number;
                div.style.pointerEvents = 'auto'; // Enable clicks on the label

                // Add Click Listener
                div.addEventListener('click', function(e) {
                    e.stopPropagation(); // Prevent map drag/click
                    openModal(m);
                });

                // Create CSS2D Object
                const label = new THREE.CSS2DObject(div);

                // Position Calculation
                // Leaflet (0,0) is bottom-left (usually in Simple CRS logic we assumed)
                // Three (0,0) is center.
                // X: m.lng - mapWidth/2
                // Y: m.lat - mapHeight/2
                const x = parseFloat(m.lng) - (mapWidth / 2);
                const y = parseFloat(m.lat) - (mapHeight / 2);

                label.position.set(x, y, 0); // Z=0 (on the plane) -> maybe Z=1 to be slightly above?
                // CSS2D is always on top anyway, but good practice.

                scene.add(label);

                // Store for filtering
                markersObjects.push({
                    data: m,
                    object: label,
                    element: div
                });
            });
        }

        // Modal Logic (Interface with existing DOM)
        function openModal(data) {
            const modal = document.getElementById(config.modalId);
            if(!modal) return;

            // Populate Data
            if(modal.querySelector('.modal-lot-number')) modal.querySelector('.modal-lot-number').innerText = data.number;
            if(modal.querySelector('.input-lot-number')) modal.querySelector('.input-lot-number').value = data.number;
            if(modal.querySelector('.modal-price')) modal.querySelector('.modal-price').innerText = data.price;
            if(modal.querySelector('.modal-area')) modal.querySelector('.modal-area').innerText = data.area;

            const statusText = { 'available': 'Disponible', 'reserved': 'Apartado', 'sold': 'Vendido' };
            const statusEl = modal.querySelector('.modal-status');
            if(statusEl) {
                statusEl.innerText = statusText[data.status] || data.status;
                statusEl.className = 'modal-status st-' + data.status;
            }

            // Show Form?
            const form = modal.querySelector('.lmp-quote-form');
            if(form) form.style.display = data.status === 'sold' ? 'none' : 'block';

            modal.style.display = "block";
        }

        // Animation Loop
        function animate() {
            requestAnimationFrame(animate);
            controls.update();
            renderer.render(scene, camera);
            labelRenderer.render(scene, camera);
        }

        // Handle Resize
        window.addEventListener('resize', function() {
            const width = container.clientWidth;
            const height = container.clientHeight;

            camera.aspect = width / height;
            camera.updateProjectionMatrix();

            renderer.setSize(width, height);
            labelRenderer.setSize(width, height);
        });

        // Filter Event Listener
        document.addEventListener('lmp-filter-change', function(e) {
            if(e.detail.containerId !== config.containerId) return;

            const filter = e.detail.filter; // 'all', 'available', 'reserved', 'sold'

            markersObjects.forEach(item => {
                if(filter === 'all' || item.data.status === filter) {
                    item.object.visible = true;
                    item.element.style.display = 'flex'; // Restore display
                } else {
                    item.object.visible = false;
                    item.element.style.display = 'none';
                }
            });
        });
    };
})();
