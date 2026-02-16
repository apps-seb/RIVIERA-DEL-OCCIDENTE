<?php
/**
 * Plugin Name: LoteMaster Pro 3D - Riviera Final
 * Description: V6.0: Solución definitiva a superposición de marcadores (LOD Dinámico), Animación de Gestos y Branding Riviera.
 * Version: 6.0.0
 * Author: Senior Full Stack Dev
 */

if (!defined('ABSPATH')) exit;

class LoteMasterPro {

    public function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_metaboxes']);
        add_action('save_post', [$this, 'save_data']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_front_assets']);
        
        add_filter('manage_masterplan_posts_columns', [$this, 'add_shortcode_column']);
        add_action('manage_masterplan_posts_custom_column', [$this, 'display_shortcode_column'], 10, 2);

        add_shortcode('lotemaster_map', [$this, 'render_map']);
        
        add_action('wp_ajax_lotemaster_send_quote', [$this, 'handle_quote']);
        add_action('wp_ajax_nopriv_lotemaster_send_quote', [$this, 'handle_quote']);
    }

    public function register_cpt() {
        register_post_type('masterplan', [
            'labels' => ['name' => 'Masterplans Riviera', 'singular_name' => 'Masterplan', 'add_new_item' => 'Crear Nuevo Mapa'],
            'public' => true,
            'supports' => ['title', 'thumbnail'], 
            'menu_icon' => 'dashicons-location-alt',
        ]);
    }

    public function enqueue_admin_assets($hook) {
        global $post;
        if (($hook == 'post-new.php' || $hook == 'post.php') && 'masterplan' === $post->post_type) {
            wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
            wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
            wp_enqueue_media();
        }
    }

    public function enqueue_front_assets() {
        wp_enqueue_script('three-js', 'https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js', [], '128', true);
        wp_enqueue_script('three-orbit', 'https://cdn.jsdelivr.net/npm/three@0.128.0/examples/js/controls/OrbitControls.js', ['three-js'], '128', true);
        wp_enqueue_script('gsap-js', 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js', [], '3.12.2', true);
    }

    public function add_metaboxes() {
        add_meta_box('lmp_map_editor', 'Editor de Lotes (Plano 2D)', [$this, 'render_admin_map'], 'masterplan', 'normal', 'high');
        add_meta_box('lmp_shortcode_info', 'Instrucciones', [$this, 'render_shortcode_info'], 'masterplan', 'side', 'high');
    }

    public function render_shortcode_info($post) {
        ?>
        <div style="background: #e7f5fe; padding: 10px; border: 1px solid #00a0d2; border-radius: 5px;">
            <p><strong>Shortcode:</strong></p>
            <code style="display:block; padding: 5px; background: white;">[lotemaster_map id="<?php echo $post->ID; ?>"]</code>
        </div>
        <?php
    }

    // --- ADMIN: EDITOR LEAFLET 2D (Sin cambios, funciona bien) ---
    public function render_admin_map($post) {
        $map_image_id = get_post_meta($post->ID, '_lmp_image_id', true);
        $map_image_url = $map_image_id ? wp_get_attachment_url($map_image_id) : '';
        $markers = get_post_meta($post->ID, '_lmp_markers', true); 
        
        echo '<input type="hidden" name="lmp_image_id" id="lmp_image_id" value="' . esc_attr($map_image_id) . '">';
        echo '<textarea name="lmp_markers_json" id="lmp_markers_json" style="display:none;">' . esc_textarea($markers) . '</textarea>';
        
        ?>
        <div style="margin-bottom: 15px;">
            <button type="button" class="button button-secondary" id="upload_map_btn">Cambiar Imagen del Plano</button>
            <span class="description"> Sube el plano. Clic para añadir lote. Arrastra para mover.</span>
        </div>

        <div style="display: flex; gap: 20px;">
            <div id="lmp-admin-map" style="flex: 2; height: 500px; background: #eee; border: 1px solid #ccc;"></div>
            
            <div id="lmp-edit-panel" style="flex: 1; background: #fff; border: 1px solid #ddd; padding: 15px; display: none;">
                <h3>Editar Lote</h3>
                <label>Número: <input type="text" id="input_lot_number" class="widefat"></label><br><br>
                <label>Precio: <input type="text" id="input_price" class="widefat"></label><br><br>
                <label>Área (m²): <input type="text" id="input_area" class="widefat"></label><br><br>
                <label>Estado:
                    <select id="input_status" class="widefat">
                        <option value="available">🟢 Disponible</option>
                        <option value="reserved">🟠 Apartado</option>
                        <option value="sold">🔴 Vendido</option>
                    </select>
                </label><br><br>
                <button type="button" class="button button-primary" id="save_point_btn">Guardar</button>
                <button type="button" class="button button-link-delete" id="delete_point_btn">Eliminar</button>
                <button type="button" class="button button-secondary" id="cancel_edit_btn">Cancelar</button>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var map, imgOverlay, markers = <?php echo $markers ? $markers : '[]'; ?>, tempMarker = null, isEditing = false, currentIdx = -1;
            var mapW = 0, mapH = 0;

            map = L.map('lmp-admin-map', { crs: L.CRS.Simple, minZoom: -3 });

            function loadMap(url) {
                if(imgOverlay) map.removeLayer(imgOverlay);
                var img = new Image();
                img.src = url;
                img.onload = function() {
                    mapW = this.width; mapH = this.height;
                    var bounds = [[0,0], [mapH, mapW]];
                    imgOverlay = L.imageOverlay(url, bounds).addTo(map);
                    map.fitBounds(bounds);
                    renderMarkers();
                }
            }

            if('<?php echo $map_image_url; ?>') loadMap('<?php echo $map_image_url; ?>');

            map.on('click', function(e) {
                if(isEditing) return;
                startEditing(e.latlng, -1);
            });

            function startEditing(latlng, index) {
                isEditing = true; currentIdx = index;
                if(tempMarker) map.removeLayer(tempMarker);
                tempMarker = L.marker(latlng, {draggable: true}).addTo(map);
                $('#lmp-edit-panel').show();
                
                if(index > -1) {
                    var d = markers[index];
                    $('#input_lot_number').val(d.number); $('#input_price').val(d.price);
                    $('#input_area').val(d.area); $('#input_status').val(d.status);
                    $('#delete_point_btn').show();
                } else {
                    $('#input_lot_number').val('').focus(); $('#input_price').val(''); $('#input_area').val('');
                    $('#input_status').val('available'); $('#delete_point_btn').hide();
                }
            }

            $('#save_point_btn').click(function() {
                var ll = tempMarker.getLatLng();
                var data = {
                    lat: ll.lat, lng: ll.lng,
                    number: $('#input_lot_number').val(), price: $('#input_price').val(),
                    area: $('#input_area').val(), status: $('#input_status').val(),
                    mapW: mapW, mapH: mapH 
                };
                if(currentIdx > -1) markers[currentIdx] = data; else markers.push(data);
                updateJSON(); resetEditor();
            });

            $('#delete_point_btn').click(function() {
                if(confirm('¿Borrar?')) { markers.splice(currentIdx, 1); updateJSON(); resetEditor(); }
            });

            $('#cancel_edit_btn').click(resetEditor);

            function resetEditor() {
                isEditing = false; currentIdx = -1;
                if(tempMarker) map.removeLayer(tempMarker);
                $('#lmp-edit-panel').hide(); renderMarkers();
            }

            function updateJSON() { $('#lmp_markers_json').val(JSON.stringify(markers)); }

            function renderMarkers() {
                map.eachLayer(l => { if(l instanceof L.Marker && l !== tempMarker) map.removeLayer(l); });
                markers.forEach((m, i) => {
                    var col = m.status === 'available' ? 'green' : (m.status === 'reserved' ? 'orange' : 'red');
                    var icon = L.divIcon({className: 'custom-pin', html: `<div style="background:${col};width:10px;height:10px;border-radius:50%;"></div>`});
                    L.marker([m.lat, m.lng], {icon: icon}).addTo(map).on('click', e => { L.DomEvent.stopPropagation(e); startEditing(e.latlng, i); });
                });
            }

            var frame;
            $('#upload_map_btn').click(function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: 'Selecciona Masterplan', multiple: false });
                frame.on('select', function() {
                    var att = frame.state().get('selection').first().toJSON();
                    $('#lmp_image_id').val(att.id); loadMap(att.url);
                });
                frame.open();
            });
        });
        </script>
        <?php
    }

    public function save_data($post_id) {
        if (isset($_POST['lmp_image_id'])) update_post_meta($post_id, '_lmp_image_id', sanitize_text_field($_POST['lmp_image_id']));
        if (isset($_POST['lmp_markers_json'])) update_post_meta($post_id, '_lmp_markers', $_POST['lmp_markers_json']);
    }

    public function add_shortcode_column($columns) { $columns['shortcode'] = 'Shortcode'; return $columns; }
    public function display_shortcode_column($column, $post_id) {
        if ($column === 'shortcode') echo '<code>[lotemaster_map id="' . $post_id . '"]</code>';
    }

    // -------------------------------------------------------------------------
    // 4. FRONTEND RENDER: LOD DINÁMICO + ANIMACIÓN GESTUAL
    // -------------------------------------------------------------------------
    public function render_map($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $post_id = $atts['id'];
        $map_image_id = get_post_meta($post_id, '_lmp_image_id', true);
        $map_image_url = $map_image_id ? wp_get_attachment_url($map_image_id) : '';
        $markers = get_post_meta($post_id, '_lmp_markers', true);
        
        $logo_url = 'https://alta57.com/wp-content/uploads/2026/02/LOGO-RIVIERA-HORIZONTAL-02.png';

        if(!$map_image_url) return '<p>No hay mapa configurado.</p>';

        $data = json_decode($markers, true);
        $total = count($data);
        $available = 0; $reserved = 0; $sold = 0;
        if($data) {
            foreach($data as $d) {
                if($d['status'] == 'available') $available++;
                elseif($d['status'] == 'reserved') $reserved++;
                elseif($d['status'] == 'sold') $sold++;
            }
        }

        ob_start();
        ?>
        <div id="lmp-app-<?php echo $post_id; ?>" class="lmp-app-container">
            
            <div id="lmp-gesture-hint-<?php echo $post_id; ?>" class="lmp-gesture-overlay">
                <div class="lmp-hand-icon"></div>
                <p>Usa dos dedos para Zoom o Scroll para acercar</p>
            </div>

            <div class="lmp-filters-scroll-container">
                <div class="lmp-filters-ui">
                    <div class="lmp-filter-card active" data-filter="all">
                        <span class="lmp-f-label">Todos</span>
                        <span class="lmp-f-count"><?php echo $total; ?></span>
                    </div>
                    <div class="lmp-filter-card" data-filter="available">
                        <span class="lmp-f-label">Disponibles</span>
                        <span class="lmp-f-count st-avail"><?php echo $available; ?></span>
                    </div>
                    <div class="lmp-filter-card" data-filter="reserved">
                        <span class="lmp-f-label">Apartados</span>
                        <span class="lmp-f-count st-res"><?php echo $reserved; ?></span>
                    </div>
                    <div class="lmp-filter-card" data-filter="sold">
                        <span class="lmp-f-label">Vendidos</span>
                        <span class="lmp-f-count st-sold"><?php echo $sold; ?></span>
                    </div>
                </div>
            </div>

            <div id="lmp-3d-canvas-<?php echo $post_id; ?>" class="lmp-canvas-wrapper">
                <div class="lmp-loader">Cargando Riviera 3D...</div>
            </div>
        </div>

        <div id="lmp-modal-<?php echo $post_id; ?>" class="lmp-modal">
            <div class="lmp-modal-content">
                <span class="lmp-close">&times;</span>
                <div class="lmp-modal-header">
                    <img src="<?php echo $logo_url; ?>" class="lmp-logo">
                    <h3 class="gold-text">LOTE <span class="modal-lot-number"></span></h3>
                </div>
                <div class="lmp-lot-details">
                    <div class="lmp-detail-row"><span>Precio</span> <strong class="modal-price gold-text"></strong></div>
                    <div class="lmp-detail-row"><span>Área Total</span> <strong class="modal-area"></strong> m²</div>
                    <div class="lmp-detail-row"><span>Estado</span> <strong class="modal-status"></strong></div>
                </div>
                <form class="lmp-quote-form">
                    <input type="hidden" name="lot_number" class="input-lot-number">
                    <input type="hidden" name="project_id" value="<?php echo $post_id; ?>">
                    <input type="text" name="name" placeholder="Nombre Completo" required>
                    <input type="tel" name="phone" placeholder="WhatsApp / Teléfono" required>
                    <input type="email" name="email" placeholder="Correo Electrónico" required>
                    <button type="submit">SOLICITAR DETALLES</button>
                    <div class="lmp-msg"></div>
                </form>
            </div>
        </div>

        <style>
            /* ESTILOS BASE */
            .lmp-app-container { 
                position: relative; width: 100%; height: 85vh; overflow: hidden; 
                font-family: 'Montserrat', 'Segoe UI', sans-serif; 
                background: linear-gradient(135deg, #061a40 0%, #030d21 100%); 
                border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.4); 
            }
            
            /* ANIMACIÓN GESTUAL (OVERLAY) */
            .lmp-gesture-overlay {
                position: absolute; top: 0; left: 0; width: 100%; height: 100%;
                background: rgba(0,0,0,0.4); z-index: 50;
                display: flex; flex-direction: column; align-items: center; justify-content: center;
                pointer-events: none; /* Permitir interacción a través */
                animation: fadeOutHint 0.5s ease 4s forwards; /* Desaparece a los 4s */
            }
            .lmp-hand-icon {
                width: 60px; height: 60px;
                background: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="white"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>'); 
                /* Icono simplificado, puedes poner una imagen de mano real */
                background-size: contain; background-repeat: no-repeat;
                opacity: 0.8;
                animation: zoomPulse 1.5s infinite;
            }
            .lmp-gesture-overlay p {
                color: white; margin-top: 15px; font-weight: bold; 
                text-transform: uppercase; letter-spacing: 1px; font-size: 12px;
                text-shadow: 0 2px 4px rgba(0,0,0,0.5);
            }
            @keyframes fadeOutHint { to { opacity: 0; visibility: hidden; } }
            @keyframes zoomPulse { 
                0% { transform: scale(1); } 
                50% { transform: scale(1.2); } 
                100% { transform: scale(1); } 
            }

            /* Resto de estilos UI (Filtros, Canvas, Modal) */
            .lmp-filters-scroll-container {
                position: absolute; top: 20px; left: 0; width: 100%; z-index: 10;
                overflow-x: auto; -webkit-overflow-scrolling: touch;
                padding-bottom: 10px; scrollbar-width: none;
            }
            .lmp-filters-scroll-container::-webkit-scrollbar { display: none; }
            .lmp-filters-ui { display: flex; gap: 10px; justify-content: center; min-width: max-content; padding: 0 20px; }
            .lmp-filter-card { 
                background: rgba(255,255,255,0.1); backdrop-filter: blur(10px); 
                padding: 10px 20px; border-radius: 30px; cursor: pointer; 
                display: flex; align-items: center; gap: 10px; 
                border: 1px solid rgba(255,255,255,0.2); transition: all 0.3s ease; color: white;
            }
            .lmp-filter-card.active { background: rgba(191, 155, 48, 0.9); border-color: #d4af37; color: #000; font-weight: bold; }
            .lmp-f-label { font-size: 13px; text-transform: uppercase; letter-spacing: 1px; }
            .lmp-f-count { background: rgba(0,0,0,0.2); padding: 2px 8px; border-radius: 10px; font-size: 11px; }
            
            .lmp-canvas-wrapper { width: 100%; height: 100%; outline: none; }
            .lmp-loader { position: absolute; top:50%; left:50%; transform:translate(-50%, -50%); color: #bf9b30; }

            /* Modal Estilos */
            .lmp-modal { display: none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(6, 26, 64, 0.6); backdrop-filter: blur(4px); }
            .lmp-modal-content { 
                background: rgba(6, 26, 64, 0.85); backdrop-filter: blur(16px);
                border: 1px solid rgba(191, 155, 48, 0.5); box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
                margin: 10vh auto; width: 90%; max-width: 380px; border-radius: 20px; overflow: hidden; 
                color: #fff;
            }
            .lmp-modal-header { padding: 25px 20px 10px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
            .lmp-logo { max-height: 40px; margin-bottom: 15px; filter: brightness(0) invert(1); }
            .gold-text { color: #bf9b30; font-weight: 700; letter-spacing: 1px; }
            .lmp-close { position: absolute; right: 20px; top: 15px; font-size: 30px; cursor: pointer; color: #bf9b30; z-index: 2; }
            .lmp-lot-details { padding: 20px 25px; }
            .lmp-detail-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 14px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 8px; }
            .lmp-detail-row span { color: rgba(255,255,255,0.6); }
            .lmp-quote-form { padding: 0 25px 30px; }
            .lmp-quote-form input { 
                width: 100%; padding: 14px; margin-bottom: 12px; 
                background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); 
                border-radius: 8px; color: white; box-sizing: border-box; 
            }
            .lmp-quote-form button { 
                width: 100%; background: linear-gradient(45deg, #bf9b30, #e3c468); 
                color: #061a40; padding: 16px; border: none; border-radius: 8px; 
                font-weight: 800; cursor: pointer; text-transform: uppercase; margin-top: 10px;
            }
            .modal-status { text-transform: uppercase; font-size: 12px; padding: 4px 8px; border-radius: 4px; }
            .st-available { color: #2ecc71; border: 1px solid #2ecc71; }
            .st-reserved { color: #f39c12; border: 1px solid #f39c12; }
            .st-sold { color: #e74c3c; border: 1px solid #e74c3c; }
        </style>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const container = document.getElementById('lmp-3d-canvas-<?php echo $post_id; ?>');
            if(!container) return;

            const rawMarkers = <?php echo $markers ? $markers : '[]'; ?>;
            const imageUrl = '<?php echo $map_image_url; ?>';

            const scene = new THREE.Scene();
            scene.background = new THREE.Color(0x061a40); 
            scene.fog = new THREE.FogExp2(0x061a40, 0.002);

            // CÁMARA INICIAL: Más alta y lejana para ver más mapa al inicio
            const camera = new THREE.PerspectiveCamera(45, container.clientWidth / container.clientHeight, 1, 6000);
            camera.position.set(0, 1500, 1200); 

            const renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
            renderer.setSize(container.clientWidth, container.clientHeight);
            renderer.setPixelRatio(window.devicePixelRatio);
            container.appendChild(renderer.domElement);

            const controls = new THREE.OrbitControls(camera, renderer.domElement);
            controls.enableDamping = true; controls.dampingFactor = 0.05;
            controls.maxPolarAngle = Math.PI / 2.1; 
            controls.minDistance = 50; 
            controls.maxDistance = 4000;

            const raycaster = new THREE.Raycaster();
            const mouse = new THREE.Vector2();
            const markersGroup = new THREE.Group();
            scene.add(markersGroup);

            const loader = new THREE.TextureLoader();
            loader.load(imageUrl, function(texture) {
                container.querySelector('.lmp-loader').style.display = 'none';
                const imgW = texture.image.width; const imgH = texture.image.height;
                const scaleFactor = 1000 / imgW;
                const planeW = imgW * scaleFactor; const planeH = imgH * scaleFactor;

                const geometry = new THREE.PlaneGeometry(planeW, planeH);
                const material = new THREE.MeshBasicMaterial({ map: texture, side: THREE.DoubleSide });
                const plane = new THREE.Mesh(geometry, material);
                plane.rotation.x = -Math.PI / 2; 
                scene.add(plane);

                rawMarkers.forEach(data => {
                    const originalMapW = parseFloat(data.mapW) || imgW; 
                    const originalMapH = parseFloat(data.mapH) || imgH;
                    
                    const normalizedX = (parseFloat(data.lng) - (originalMapW / 2)) / originalMapW;
                    const posX = normalizedX * planeW;

                    const normalizedY = (parseFloat(data.lat) - (originalMapH / 2)) / originalMapH;
                    const posZ = -normalizedY * planeH; 

                    const color = data.status === 'available' ? '#2ecc71' : (data.status === 'reserved' ? '#f39c12' : '#e74c3c');
                    
                    // AUMENTAR RESOLUCIÓN CANVAS PARA TEXTO NÍTIDO EN TAMAÑO PEQUEÑO
                    const canvas = document.createElement('canvas');
                    canvas.width = 128; canvas.height = 128; // Doble resolución
                    const ctx = canvas.getContext('2d');
                    
                    // Círculo
                    ctx.beginPath();
                    ctx.arc(64, 64, 58, 0, 2 * Math.PI);
                    ctx.fillStyle = color; ctx.fill();
                    ctx.lineWidth = 6; ctx.strokeStyle = 'white'; ctx.stroke();
                    ctx.shadowColor = "rgba(0,0,0,0.5)"; ctx.shadowBlur = 15;
                    
                    // Texto
                    ctx.fillStyle = 'white'; 
                    ctx.font = 'bold 44px Arial'; // Fuente más grande relativa al canvas
                    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
                    ctx.shadowBlur = 0; 
                    ctx.fillText(data.number, 64, 64);

                    const spriteMap = new THREE.CanvasTexture(canvas);
                    const spriteMat = new THREE.SpriteMaterial({ map: spriteMap, sizeAttenuation: true });
                    const sprite = new THREE.Sprite(spriteMat);
                    
                    sprite.position.set(posX, 20, posZ); 
                    
                    // TAMAÑO BASE REDUCIDO PARA EVITAR SUPERPOSICIÓN INICIAL
                    // Antes 25/40, ahora 12.
                    sprite.scale.set(12, 12, 1); 
                    
                    sprite.userData = data; 
                    // Guardar escala base para animaciones
                    sprite.userData.baseScale = 12;
                    
                    markersGroup.add(sprite);
                });

                // ANIMACIÓN DE ENTRADA LLAMATIVA
                gsap.from(camera.position, { duration: 3, y: 3000, z: 2000, ease: "power3.out" });
            });

            // --- ANIMATION LOOP CON LÓGICA LOD (LEVEL OF DETAIL) ---
            function animate() {
                requestAnimationFrame(animate);
                controls.update();
                
                // LÓGICA DINÁMICA DE TAMAÑO SEGÚN DISTANCIA DE CÁMARA
                // Esto previene que se vean "bolas gigantes" cuando estás lejos
                if(markersGroup.children.length > 0) {
                    const camDist = camera.position.distanceTo(new THREE.Vector3(0,0,0)); // Distancia aprox al centro
                    
                    markersGroup.children.forEach(sprite => {
                        // Si la cámara está muy lejos (> 1500), reducir escala gradualmente
                        // Si la cámara está cerca (< 800), mantener tamaño legible
                        
                        let targetScale = sprite.userData.baseScale;
                        
                        if (camDist > 1200) {
                            // Lejos: Hacerlos más pequeños para evitar solapamiento masivo
                            targetScale = 8; 
                        } else if (camDist < 600) {
                            // Muy Cerca: Aumentar un poco para legibilidad
                            targetScale = 15;
                        } else {
                            // Intermedio
                            targetScale = 12;
                        }
                        
                        // Si está filtrado y oculto, forzar 0
                        if(sprite.visible === false && sprite.scale.x < 1) targetScale = 0;

                        // Lerp simple para suavidad (solo si es visible por filtro)
                        if(sprite.visible) {
                           sprite.scale.lerp(new THREE.Vector3(targetScale, targetScale, 1), 0.1);
                        }
                    });
                }

                renderer.render(scene, camera);
            }
            animate();

            // --- CLICK HANDLING ---
            let isDragging = false;
            let startX = 0, startY = 0;

            renderer.domElement.addEventListener('mousedown', (e) => { isDragging = false; startX = e.clientX; startY = e.clientY; });
            renderer.domElement.addEventListener('mouseup', (e) => {
                if (Math.abs(e.clientX - startX) < 5 && Math.abs(e.clientY - startY) < 5) onCanvasClick(e);
            });
            renderer.domElement.addEventListener('touchstart', (e) => { startX = e.touches[0].clientX; startY = e.touches[0].clientY; }, {passive: false});
            renderer.domElement.addEventListener('touchend', (e) => {
                if (Math.abs(e.changedTouches[0].clientX - startX) < 5 && Math.abs(e.changedTouches[0].clientY - startY) < 5) onCanvasClick(e.changedTouches[0]);
            }, {passive: false});

            function onCanvasClick(event) {
                const rect = renderer.domElement.getBoundingClientRect();
                mouse.x = ((event.clientX - rect.left) / rect.width) * 2 - 1;
                mouse.y = -((event.clientY - rect.top) / rect.height) * 2 + 1;
                raycaster.setFromCamera(mouse, camera);
                const intersects = raycaster.intersectObjects(markersGroup.children);

                if (intersects.length > 0) {
                    const obj = intersects[0].object;
                    openModal(obj.userData);
                    // Acercar la cámara significativamente al hacer click para enfocar el lote
                    gsap.to(camera.position, { duration: 1.5, x: obj.position.x, y: 150, z: obj.position.z + 100, ease: "power2.out" });
                    controls.target.set(obj.position.x, 0, obj.position.z);
                }
            }

            // --- FILTROS ---
            const filterCards = document.querySelectorAll('.lmp-filter-card');
            filterCards.forEach(card => {
                card.addEventListener('click', function() {
                    filterCards.forEach(c => c.classList.remove('active'));
                    this.classList.add('active');
                    const filter = this.dataset.filter;
                    markersGroup.children.forEach(sprite => {
                        const isVisible = (filter === 'all' || sprite.userData.status === filter);
                        if(isVisible) { 
                            sprite.visible = true; 
                            // Restaurar al tamaño base, el loop de animación se encarga del resto
                            // No usamos gsap aquí para evitar conflicto con el loop animate()
                        } else { 
                            // Para ocultar, sí usamos animación rápida a 0
                            gsap.to(sprite.scale, {duration: 0.3, x: 0, y: 0, onComplete: () => { sprite.visible = false; }}); 
                        }
                    });
                });
            });

            window.addEventListener('resize', () => {
                camera.aspect = container.clientWidth / container.clientHeight;
                camera.updateProjectionMatrix(); renderer.setSize(container.clientWidth, container.clientHeight);
            });

            // --- MODAL ---
            var modal = document.getElementById("lmp-modal-<?php echo $post_id; ?>");
            var close = modal.querySelector(".lmp-close");
            var form = modal.querySelector(".lmp-quote-form");

            function openModal(data) {
                modal.querySelector('.modal-lot-number').innerText = data.number;
                modal.querySelector('.input-lot-number').value = data.number;
                modal.querySelector('.modal-price').innerText = data.price;
                modal.querySelector('.modal-area').innerText = data.area;
                var statusText = { 'available': 'Disponible', 'reserved': 'Apartado', 'sold': 'Vendido' };
                var statusEl = modal.querySelector('.modal-status');
                statusEl.innerText = statusText[data.status];
                statusEl.className = 'modal-status st-' + data.status;
                form.style.display = data.status === 'sold' ? 'none' : 'block';
                modal.style.display = "block";
            }
            close.onclick = function() { modal.style.display = "none"; }
            
            form.addEventListener('submit', function(e){
                e.preventDefault();
                var btn = form.querySelector('button');
                var origText = btn.innerText;
                btn.innerText = 'PROCESANDO...'; btn.disabled = true;
                var fd = new FormData(form); fd.append('action', 'lotemaster_send_quote');
                fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: fd })
                .then(r => r.json()).then(d => {
                    modal.querySelector('.lmp-msg').innerText = '¡Enviado! Revisa tu correo.';
                    setTimeout(() => { modal.style.display="none"; btn.disabled=false; btn.innerText=origText; form.reset(); modal.querySelector('.lmp-msg').innerText=''; }, 2500);
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    // --- EMAIL ---
    public function handle_quote() {
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $lot = sanitize_text_field($_POST['lot_number']);
        $logo_url = 'https://alta57.com/wp-content/uploads/2026/02/LOGO-RIVIERA-HORIZONTAL-02.png';
        
        add_filter( 'wp_mail_from', function() { return 'inversionistas@alta57.com'; } );
        add_filter( 'wp_mail_from_name', function() { return 'Proyecto Riviera del Occidente'; } );

        $subject = "Confirmación de Interés - Lote #$lot";
        
        $message = '
        <!DOCTYPE html>
        <html>
        <head>
            <style>
                body { font-family: "Helvetica Neue", Helvetica, Arial, sans-serif; background-color: #f4f4f4; margin: 0; padding: 0; }
                .email-container { max-width: 600px; margin: 0 auto; background-color: #061a40; color: #ffffff; overflow: hidden; }
                .header { background-color: #061a40; padding: 40px 20px; text-align: center; border-bottom: 2px solid #bf9b30; }
                .logo { max-width: 180px; }
                .content { padding: 40px 30px; background-color: #061a40; color: #ffffff; }
                .greeting { font-size: 24px; color: #bf9b30; margin-bottom: 20px; font-weight: 300; }
                .text { line-height: 1.6; color: #e0e0e0; font-size: 16px; margin-bottom: 20px; }
                .lot-card { background: rgba(255,255,255,0.05); border: 1px solid #bf9b30; padding: 20px; margin: 30px 0; text-align: center; border-radius: 4px; }
                .lot-number { font-size: 32px; color: #bf9b30; font-weight: bold; margin: 0; }
                .lot-label { font-size: 12px; text-transform: uppercase; letter-spacing: 2px; color: #aaa; }
                .btn { display: inline-block; background-color: #bf9b30; color: #061a40; padding: 15px 30px; text-decoration: none; font-weight: bold; border-radius: 4px; margin-top: 20px; }
                .footer { background-color: #030d21; padding: 20px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="email-container">
                <div class="header">
                    <img src="'.$logo_url.'" alt="Riviera Logo" class="logo">
                </div>
                <div class="content">
                    <h1 class="greeting">Estimado/a '.$name.',</h1>
                    <p class="text">Agradecemos profundamente su interés en el <strong>Proyecto Riviera del Occidente</strong>.</p>
                    <div class="lot-card">
                        <p class="lot-label">Unidad Seleccionada</p>
                        <p class="lot-number">LOTE '.$lot.'</p>
                    </div>
                    <p class="text">Un asesor especializado se pondrá en contacto con usted al número <strong>'.$phone.'</strong>.</p>
                    <div style="text-align: center;">
                        <a href="https://rivieradeloccidente.alta57.com/" class="btn">VISITAR SITIO WEB</a>
                    </div>
                </div>
                <div class="footer">
                    &copy; 2026 Proyecto Riviera del Occidente.
                </div>
            </div>
        </body>
        </html>
        ';

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($email, $subject, $message, $headers);
        wp_mail('inversionistas@alta57.com', "Nuevo Lead Riviera - Lote $lot", "Cliente: $name\nTel: $phone\nEmail: $email");

        wp_send_json_success();
    }
}

new LoteMasterPro();
