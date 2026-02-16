<?php
/**
 * Plugin Name: LoteMaster Pro (Interactive Map)
 * Description: V4.1 - Corrección Crítica de Zoom y Coordenadas (Zero-Offset Architecture + Dynamic Zoom).
 * Version: 4.1.0
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

    // 1. CPT
    public function register_cpt() {
        register_post_type('masterplan', [
            'labels' => ['name' => 'Masterplans', 'singular_name' => 'Masterplan', 'add_new_item' => 'Crear Nuevo Mapa'],
            'public' => true,
            'supports' => ['title', 'thumbnail'], 
            'menu_icon' => 'dashicons-location-alt',
        ]);
    }

    // 2. Assets
    public function enqueue_admin_assets($hook) {
        global $post;
        if (($hook == 'post-new.php' || $hook == 'post.php') && 'masterplan' === $post->post_type) {
            wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
            wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
            wp_enqueue_media();
        }
    }

    public function enqueue_front_assets() {
        wp_enqueue_style('leaflet-css', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css');
        wp_enqueue_script('leaflet-js', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', [], '1.9.4', true);
    }

    // 3. Metaboxes
    public function add_metaboxes() {
        add_meta_box('lmp_map_editor', 'Editor Visual del Masterplan', [$this, 'render_admin_map'], 'masterplan', 'normal', 'high');
        add_meta_box('lmp_shortcode_info', 'Instrucciones', [$this, 'render_shortcode_info'], 'masterplan', 'side', 'high');
    }

    public function render_shortcode_info($post) {
        ?>
        <div style="background: #e7f5fe; padding: 10px; border: 1px solid #00a0d2; border-radius: 5px;">
            <p><strong>Shortcode:</strong></p>
            <code style="display:block; padding: 10px; background: white; border: 1px solid #ddd; user-select: all;">[lotemaster_map id="<?php echo $post->ID; ?>"]</code>
        </div>
        <?php
    }

    // --- EDITOR ADMIN (CORREGIDO: ZOOM ROBUSTO) ---
    public function render_admin_map($post) {
        $map_image_id = get_post_meta($post->ID, '_lmp_image_id', true);
        $map_image_url = $map_image_id ? wp_get_attachment_url($map_image_id) : '';
        $markers = get_post_meta($post->ID, '_lmp_markers', true); 
        $initial_zoom = get_post_meta($post->ID, '_lmp_initial_zoom', true);
        
        // Sanitización para JS: Reemplazar coma por punto si existe
        $initial_zoom_js = str_replace(',', '.', $initial_zoom);

        echo '<input type="hidden" name="lmp_image_id" id="lmp_image_id" value="' . esc_attr($map_image_id) . '">';
        echo '<textarea name="lmp_markers_json" id="lmp_markers_json" style="display:none;">' . esc_textarea($markers) . '</textarea>';
        
        ?>
        <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
            <button type="button" class="button button-secondary" id="upload_map_btn"><span class="dashicons dashicons-format-image"></span> Cambiar Imagen</button>
            <label style="margin-left: 15px;">
                <strong>Zoom Inicial:</strong>
                <!-- Usamos 'step="any"' para permitir decimales libres -->
                <input type="number" step="any" name="lmp_initial_zoom" value="<?php echo esc_attr($initial_zoom_js); ?>" style="width: 70px;" placeholder="Auto">
            </label>
            <span class="description">Haz clic para añadir. Arrastra para mover. (Vacío = Ajustar a pantalla)</span>
        </div>

        <div style="display: flex; gap: 20px;">
            <div id="lmp-admin-map" style="flex: 2; height: 600px; background: #f0f0f0; border: 1px solid #ccc;"></div>
            
            <div id="lmp-edit-panel" style="flex: 1; background: #fff; border: 1px solid #ddd; padding: 20px; display: none;">
                <h3 style="margin-top:0;">Editar Lote</h3>
                <label style="display:block; margin-bottom:10px;"><strong>Nº Lote:</strong> <input type="text" id="input_lot_number" class="widefat"></label>
                <label style="display:block; margin-bottom:10px;"><strong>Precio:</strong> <input type="text" id="input_price" class="widefat"></label>
                <label style="display:block; margin-bottom:10px;"><strong>Área (m²):</strong> <input type="text" id="input_area" class="widefat"></label>
                <label style="display:block; margin-bottom:15px;"><strong>Estado:</strong>
                    <select id="input_status" class="widefat">
                        <option value="available">🟢 Disponible</option>
                        <option value="reserved">🟠 Apartado</option>
                        <option value="sold">🔴 Vendido</option>
                    </select>
                </label>
                <div style="display: flex; gap: 10px;">
                    <button type="button" class="button button-primary" id="save_point_btn" style="flex:1;">Guardar</button>
                    <button type="button" class="button button-link-delete" id="delete_point_btn">Eliminar</button>
                    <button type="button" class="button button-secondary" id="cancel_edit_btn">Cancelar</button>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var map, imgOverlay;
            var markers = <?php echo $markers ? $markers : '[]'; ?>;
            var tempMarker = null; 
            var currentDataIndex = -1; 

            // ICONO ORIGINAL RESTAURADO (Drag)
            var iconTemp = L.divIcon({ className: 'lmp-marker-temp', html: '<span class="dashicons dashicons-move"></span>', iconSize: [30, 30], iconAnchor: [15, 15] });

            // Inicializar mapa sin minZoom restrictivo por defecto
            map = L.map('lmp-admin-map', {
                crs: L.CRS.Simple,
                minZoom: -5, // Permitir zoom alejado para imágenes grandes
                zoomSnap: 0.1
            });

            var zoomInput = $('input[name="lmp_initial_zoom"]');

            function loadMap(url) {
                if(imgOverlay) map.removeLayer(imgOverlay);
                var img = new Image();
                img.onload = function() {
                    var bounds = [[0,0], [this.height, this.width]];
                    imgOverlay = L.imageOverlay(url, bounds).addTo(map);

                    // 1. Establecer límites pero permitir zoom libre en Admin
                    var fitZoom = map.getBoundsZoom(bounds);
                    map.setMinZoom(-5); // Permitir alejar bastante siempre

                    var savedZoom = parseFloat(zoomInput.val());

                    // 2. Prioridad al zoom guardado
                    if(!isNaN(savedZoom)) {
                        map.setView(bounds.getCenter(), savedZoom);
                    } else {
                        // Si no hay zoom guardado, ajustar a la imagen
                        map.fitBounds(bounds);
                    }

                    renderMarkers();
                }
                img.src = url;
            }

            if('<?php echo $map_image_url; ?>') loadMap('<?php echo $map_image_url; ?>');

            // --- REAL-TIME ZOOM SYNC ---
            map.on('zoomend', function() {
                // Si el input está enfocado (escribiendo), no sobreescribir para no molestar
                if(!zoomInput.is(":focus")) {
                    // Redondear a 2 decimales para limpieza visual
                    var z = Math.round(map.getZoom() * 100) / 100;
                    zoomInput.val(z);
                }
            });

            zoomInput.on('change input', function() {
                var val = $(this).val();
                if(val === '' && imgOverlay) {
                    map.fitBounds(imgOverlay.getBounds());
                } else {
                    // Reemplazar coma por punto para parseo JS seguro
                    val = val.replace(',', '.');
                    var num = parseFloat(val);
                    if(!isNaN(num)) {
                         // Asegurar que el mapa permita este zoom
                         if(num < map.getMinZoom()) map.setMinZoom(num);
                         map.setZoom(num);
                    }
                }
            });
            // ---------------------------

            map.on('click', function(e) {
                if($('#lmp-edit-panel').is(':visible') && currentDataIndex > -1) return alert("Guarda el lote actual primero.");
                startEditing(e.latlng, -1);
            });

            function startEditing(latlng, index) {
                currentDataIndex = index;
                if(tempMarker) map.removeLayer(tempMarker);
                
                tempMarker = L.marker(latlng, {icon: iconTemp, draggable: true}).addTo(map);
                
                $('#lmp-edit-panel').show();
                if(index > -1) {
                    var data = markers[index];
                    $('#input_lot_number').val(data.number);
                    $('#input_price').val(data.price);
                    $('#input_area').val(data.area);
                    $('#input_status').val(data.status);
                    $('#delete_point_btn').show();
                } else {
                    $('#input_lot_number').val('').focus();
                    $('#input_price').val('');
                    $('#input_area').val('');
                    $('#input_status').val('available');
                    $('#delete_point_btn').hide();
                }
            }

            $('#save_point_btn').click(function() {
                if(!tempMarker) return;
                var data = {
                    lat: tempMarker.getLatLng().lat,
                    lng: tempMarker.getLatLng().lng,
                    number: $('#input_lot_number').val(),
                    price: $('#input_price').val(),
                    area: $('#input_area').val(),
                    status: $('#input_status').val()
                };
                if(currentDataIndex > -1) markers[currentDataIndex] = data;
                else markers.push(data);
                
                updateJSON();
                resetEditor();
            });

            $('#delete_point_btn').click(function() {
                if(confirm('¿Eliminar?')) {
                    if(currentDataIndex > -1) {
                        markers.splice(currentDataIndex, 1);
                        updateJSON();
                    }
                    resetEditor();
                }
            });

            $('#cancel_edit_btn').click(function() { resetEditor(); });

            function resetEditor() {
                currentDataIndex = -1;
                if(tempMarker) map.removeLayer(tempMarker);
                $('#lmp-edit-panel').hide();
                renderMarkers();
            }

            function updateJSON() { $('#lmp_markers_json').val(JSON.stringify(markers)); }

            function renderMarkers() {
                map.eachLayer(function(layer){ if(layer instanceof L.Marker && layer !== tempMarker) map.removeLayer(layer); });
                markers.forEach(function(m, index) {
                    var color = m.status === 'available' ? '#4CAF50' : (m.status === 'reserved' ? '#FF9800' : '#F44336');
                    // Icono estático simple para admin
                    var icon = L.divIcon({
                        className: 'lmp-marker-saved',
                        html: '<div style="background:'+color+'; width:20px; height:20px; border-radius:50%; border:2px solid white; color:white; text-align:center; line-height:16px; font-size:10px;">'+m.number+'</div>',
                        iconSize: [24, 24],
                        iconAnchor: [12, 12]
                    });

                    var marker = L.marker([m.lat, m.lng], {icon: icon}).addTo(map);
                    marker.on('click', function(e) { L.DomEvent.stopPropagation(e); startEditing(e.latlng, index); });
                });
            }

            var frame;
            $('#upload_map_btn').click(function(e) {
                e.preventDefault();
                if (frame) { frame.open(); return; }
                frame = wp.media({ title: 'Selecciona el Masterplan', button: { text: 'Usar imagen' }, multiple: false });
                frame.on('select', function() {
                    var attachment = frame.state().get('selection').first().toJSON();
                    $('#lmp_image_id').val(attachment.id);
                    loadMap(attachment.url);
                });
                frame.open();
            });
        });
        </script>
        <style>
            .lmp-marker-temp { 
                background: rgba(33, 150, 243, 0.9); 
                border-radius: 50%; 
                border: 2px solid white; 
                box-shadow: 0 0 10px rgba(0,0,0,0.5); 
                color: white; 
                text-align: center;
                cursor: grab;
            }
            .lmp-marker-temp span { line-height: 26px; }
            .lmp-marker-saved:hover { transform: scale(1.2); z-index: 999; cursor: pointer; }
        </style>
        <?php
    }

    public function save_data($post_id) {
        if (isset($_POST['lmp_image_id'])) update_post_meta($post_id, '_lmp_image_id', sanitize_text_field($_POST['lmp_image_id']));
        if (isset($_POST['lmp_markers_json'])) update_post_meta($post_id, '_lmp_markers', $_POST['lmp_markers_json']); // JSON raw is ok here as wp sanitizes
        // Sanitizar zoom asegurando formato numérico simple
        if (isset($_POST['lmp_initial_zoom'])) {
            $zoom = sanitize_text_field($_POST['lmp_initial_zoom']);
            $zoom = str_replace(',', '.', $zoom); // Normalizar
            update_post_meta($post_id, '_lmp_initial_zoom', $zoom);
        }
    }

    public function add_shortcode_column($columns) { $columns['shortcode'] = 'Shortcode'; return $columns; }
    public function display_shortcode_column($column, $post_id) { if ($column === 'shortcode') echo '<code>[lotemaster_map id="' . $post_id . '"]</code>'; }

    // =================================================================================
    // 5. RENDER FRONTEND (CORREGIDO CRÍTICAMENTE)
    // =================================================================================
    public function render_map($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $post_id = $atts['id'];
        $map_image_id = get_post_meta($post_id, '_lmp_image_id', true);
        $map_image_url = $map_image_id ? wp_get_attachment_url($map_image_id) : '';
        $markers = get_post_meta($post_id, '_lmp_markers', true);
        $initial_zoom = get_post_meta($post_id, '_lmp_initial_zoom', true);
        // Sanitización frontend también por seguridad
        $initial_zoom = str_replace(',', '.', $initial_zoom);

        $logo_url = get_the_post_thumbnail_url($post_id, 'full');
        
        if(!$map_image_url) return '';

        // Calcular Estadísticas
        $markers_data = json_decode($markers, true);
        if(!is_array($markers_data)) $markers_data = [];
        $stats = ['total' => count($markers_data), 'available' => 0, 'reserved' => 0, 'sold' => 0];
        foreach($markers_data as $m) {
            if(isset($stats[$m['status']])) $stats[$m['status']]++;
        }

        ob_start();
        ?>
        <div class="lmp-filter-container">
            <button class="lmp-tab active" data-filter="all"><span class="count"><?php echo $stats['total']; ?></span> Todos</button>
            <button class="lmp-tab tab-available" data-filter="available"><span class="count"><?php echo $stats['available']; ?></span> Disponibles</button>
            <button class="lmp-tab tab-reserved" data-filter="reserved"><span class="count"><?php echo $stats['reserved']; ?></span> Apartados</button>
            <button class="lmp-tab tab-sold" data-filter="sold"><span class="count"><?php echo $stats['sold']; ?></span> Vendidos</button>
        </div>

        <div id="lmp-wrapper-<?php echo $post_id; ?>" class="lmp-wrapper" style="--point-scale: 1;">
            
            <div id="lmp-front-map-<?php echo $post_id; ?>" class="lmp-map-container"></div>
            
            <div class="lmp-size-control">
                <label>Tamaño</label>
                <input type="range" id="size-slider-<?php echo $post_id; ?>" min="0.4" max="2.5" step="0.1" value="1">
            </div>
        </div>
        
        <div id="lmp-modal-<?php echo $post_id; ?>" class="lmp-modal">
            <div class="lmp-modal-content">
                <span class="lmp-close">&times;</span>
                <div class="lmp-modal-header">
                    <?php if($logo_url) echo '<img src="'.$logo_url.'" class="lmp-logo">'; ?>
                    <h3>Lote #<span class="modal-lot-number"></span></h3>
                </div>
                <div class="lmp-lot-details">
                    <div class="lmp-detail-row"><span>Precio:</span> <strong class="modal-price"></strong></div>
                    <div class="lmp-detail-row"><span>Área:</span> <strong class="modal-area"></strong> m²</div>
                    <div class="lmp-detail-row"><span>Estado:</span> <strong class="modal-status"></strong></div>
                </div>
                <form class="lmp-quote-form">
                    <input type="hidden" name="lot_number" class="input-lot-number">
                    <input type="hidden" name="project_id" value="<?php echo $post_id; ?>">
                    <input type="text" name="name" placeholder="Tu Nombre Completo" required>
                    <input type="tel" name="phone" placeholder="Celular / WhatsApp" required>
                    <input type="email" name="email" placeholder="Correo Electrónico" required>
                    <button type="submit">SOLICITAR COTIZACIÓN</button>
                    <div class="lmp-msg"></div>
                </form>
            </div>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var mapId = 'lmp-front-map-<?php echo $post_id; ?>';
            if(!document.getElementById(mapId)) return;

            // Inicialización Frontend: minZoom flexible para empezar
            var map = L.map(mapId, { 
                crs: L.CRS.Simple, 
                minZoom: -5, // Permitir zoom amplio
                maxZoom: 3,  // Permitir zoom detallado
                zoomSnap: 0.1 
            });

            var bounds;
            var img = new Image();
            var initialZoom = '<?php echo $initial_zoom; ?>';
            
            img.onload = function() {
                bounds = [[0,0], [this.height, this.width]];
                L.imageOverlay('<?php echo $map_image_url; ?>', bounds).addTo(map);

                // 1. Establecer límites pero permitir zoom libre
                var fitZoom = map.getBoundsZoom(bounds);
                map.setMinZoom(-5); // Permitir alejar bastante siempre

                var zoomVal = parseFloat(initialZoom);

                // 2. Prioridad al zoom guardado
                if(!isNaN(zoomVal)) {
                    map.setView(bounds.getCenter(), zoomVal);
                } else {
                    // Si no hay zoom guardado, ajustar a la imagen
                    map.fitBounds(bounds);
                }
            }
            img.src = '<?php echo $map_image_url; ?>';

            var slider = document.getElementById('size-slider-<?php echo $post_id; ?>');
            var wrapper = document.getElementById('lmp-wrapper-<?php echo $post_id; ?>');
            
            if(slider) {
                slider.addEventListener('input', function() {
                    wrapper.style.setProperty('--point-scale', this.value);
                });
            }

            var markers = <?php echo $markers ? $markers : '[]'; ?>;
            var modal = document.getElementById("lmp-modal-<?php echo $post_id; ?>");
            var close = modal.querySelector(".lmp-close");
            var form = modal.querySelector(".lmp-quote-form");

            var markerInstances = [];

            markers.forEach(function(m) {
                var colorClass = 'status-' + m.status;
                
                var icon = L.divIcon({
                    className: 'lmp-marker-container-layer',
                    html: '<div class="lmp-inner-dot ' + colorClass + '"><span class="marker-label">' + m.number + '</span></div>',
                    iconSize: [0, 0],
                    iconAnchor: [0, 0]
                });

                var marker = L.marker([m.lat, m.lng], {icon: icon}).addTo(map);
                
                marker.bindTooltip("Lote " + m.number, { direction: 'top', offset: [0, -15] });
                marker.on('click', function() { openModal(m); });

                marker.status = m.status;
                markerInstances.push(marker);
            });

            // FILTRADO
            var tabs = document.querySelectorAll('.lmp-filter-container .lmp-tab');
            tabs.forEach(function(tab) {
                tab.addEventListener('click', function() {
                    tabs.forEach(function(t) { t.classList.remove('active'); });
                    this.classList.add('active');
                    var filter = this.getAttribute('data-filter');

                    markerInstances.forEach(function(mk) {
                        if(filter === 'all' || mk.status === filter) {
                            if(!map.hasLayer(mk)) map.addLayer(mk);
                        } else {
                            if(map.hasLayer(mk)) map.removeLayer(mk);
                        }
                    });
                });
            });

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
            window.onclick = function(event) { if (event.target == modal) { modal.style.display = "none"; } }

            form.addEventListener('submit', function(e){
                e.preventDefault();
                var formData = new FormData(form);
                formData.append('action', 'lotemaster_send_quote');
                
                var btn = form.querySelector('button');
                var originalText = btn.innerText;
                btn.innerText = 'Enviando...';
                btn.disabled = true;

                fetch('<?php echo admin_url('admin-ajax.php'); ?>', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    modal.querySelector('.lmp-msg').innerText = data.data.message;
                    btn.innerText = '¡ENVIADO!';
                    setTimeout(() => { modal.style.display = "none"; btn.disabled = false; btn.innerText = originalText; form.reset(); modal.querySelector('.lmp-msg').innerText=''; }, 2000);
                });
            });
        });
        </script>
        <style>
            /* TABS CSS */
            .lmp-filter-container { display: flex; gap: 10px; margin-bottom: 15px; flex-wrap: wrap; justify-content: center; }
            .lmp-tab {
                border: none; background: #f1f5f9; color: #64748b; padding: 10px 20px; border-radius: 30px;
                cursor: pointer; font-weight: bold; font-family: sans-serif; transition: all 0.3s ease;
                display: flex; align-items: center; gap: 8px; font-size: 14px;
            }
            .lmp-tab .count { background: #cbd5e1; color: #fff; padding: 2px 8px; border-radius: 10px; font-size: 11px; }
            .lmp-tab:hover { background: #e2e8f0; transform: translateY(-2px); }

            .lmp-tab.active { background: #0f172a; color: white; box-shadow: 0 4px 12px rgba(15, 23, 42, 0.3); }
            .lmp-tab.active .count { background: rgba(255,255,255,0.2); }

            .lmp-tab.tab-available.active { background: #2ecc71; box-shadow: 0 4px 12px rgba(46, 204, 113, 0.4); }
            .lmp-tab.tab-reserved.active { background: #f39c12; box-shadow: 0 4px 12px rgba(243, 156, 18, 0.4); }
            .lmp-tab.tab-sold.active { background: #e74c3c; box-shadow: 0 4px 12px rgba(231, 76, 60, 0.4); }

            .lmp-wrapper { position: relative; }
            .lmp-map-container { width: 100%; height: 85vh; background: #eee; border-radius: 8px; border: 1px solid #ddd; z-index: 1; }

            .lmp-size-control {
                position: absolute; top: 10px; right: 10px; background: white; padding: 10px; border-radius: 5px;
                box-shadow: 0 2px 5px rgba(0,0,0,0.2); z-index: 1000; display: flex; flex-direction: column; align-items: center; gap: 5px; font-family: sans-serif; font-size: 12px;
            }
            .lmp-size-control input[type=range] { cursor: pointer; }

            /* CLASE DEL CONTENEDOR LEAFLET (INVISIBLE) */
            .lmp-marker-container-layer {
                background: transparent;
                border: none;
            }

            /* CLASE VISUAL DEL PUNTO (CENTRADO Y ESCALADO) */
            .lmp-inner-dot {
                /* Tamaño Base * Factor de Escala */
                width: calc(28px * var(--point-scale));
                height: calc(28px * var(--point-scale));
                
                /* Centrado Absoluto respecto al contenedor [0,0] */
                position: absolute;
                top: 0;
                left: 0;
                transform: translate(-50%, -50%); /* ESTO CENTRA EL PUNTO SOBRE LA COORDENADA */
                
                border-radius: 50%;
                text-align: center;
                border: 2px solid white;
                background: white;
                color: white;
                font-weight: bold;
                box-shadow: 0 2px 4px rgba(0,0,0,0.4);
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                overflow: hidden;
            }

            .lmp-inner-dot .marker-label {
                font-size: calc(11px * var(--point-scale));
                line-height: 1;
                pointer-events: none;
            }

            .status-available { background-color: #2ecc71; border-color: #fff; }
            .status-reserved  { background-color: #f39c12; border-color: #fff; }
            .status-sold      { background-color: #e74c3c; border-color: #fff; opacity: 0.8; }

            .lmp-inner-dot:hover {
                z-index: 9999;
                transform: translate(-50%, -50%) scale(1.3); /* Mantiene el centro y agranda */
            }

            /* MODAL STYLES */
            .lmp-modal { display: none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.6); backdrop-filter: blur(2px); }
            .lmp-modal-content { background-color: #fff; margin: 5% auto; width: 90%; max-width: 420px; border-radius: 12px; box-shadow: 0 25px 50px rgba(0,0,0,0.3); overflow: hidden; font-family: sans-serif; }
            .lmp-modal-header { background: #f8fafc; padding: 20px; text-align: center; border-bottom: 1px solid #e2e8f0; }
            .lmp-logo { max-height: 50px; margin-bottom: 10px; display: block; margin: 0 auto 10px; }
            .lmp-close { position: absolute; right: 20px; top: 15px; font-size: 28px; cursor: pointer; color: #999; }
            .lmp-lot-details { padding: 20px; background: #fff; }
            .lmp-detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px dashed #e2e8f0; font-size: 14px; }
            .lmp-quote-form { padding: 20px; background: #f1f5f9; }
            .lmp-quote-form input { width: 100%; padding: 12px; margin-bottom: 10px; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; }
            .lmp-quote-form button { width: 100%; background: #0f172a; color: white; padding: 14px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; }
            .lmp-quote-form button:hover { background: #334155; }
            .st-available { color: #2ecc71; } .st-reserved { color: #f39c12; } .st-sold { color: #e74c3c; }
            .lmp-msg { text-align: center; margin-top: 10px; font-size: 13px; color: #059669; font-weight: bold; }
        </style>
        <?php
        return ob_get_clean();
    }

    public function handle_quote() {
        $name = sanitize_text_field($_POST['name']);
        $lot = sanitize_text_field($_POST['lot_number']);
        wp_send_json_success(['message' => "¡Recibido! Gracias $name."]);
    }
}

new LoteMasterPro();
