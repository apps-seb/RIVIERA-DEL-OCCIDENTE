<?php
/**
 * Plugin Name: LoteMaster Pro (Interactive Map)
 * Description: Sistema profesional de gestión de masterplans. Versión 2.0 con edición de precisión y shortcodes.
 * Version: 2.0.0
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
        
        // Columnas personalizadas para ver el Shortcode rápido
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

    // 3. Metaboxes (Editor y Shortcode Info)
    public function add_metaboxes() {
        add_meta_box('lmp_map_editor', 'Editor Visual del Masterplan', [$this, 'render_admin_map'], 'masterplan', 'normal', 'high');
        add_meta_box('lmp_shortcode_info', '¿Cómo mostrar este mapa?', [$this, 'render_shortcode_info'], 'masterplan', 'side', 'high');
    }

    // --- NUEVO: Caja lateral con instrucciones ---
    public function render_shortcode_info($post) {
        ?>
        <div style="background: #e7f5fe; padding: 10px; border: 1px solid #00a0d2; border-radius: 5px;">
            <p><strong>Para ver este mapa en tu web:</strong></p>
            <p>1. Copia este código:</p>
            <code style="display:block; padding: 10px; background: white; border: 1px solid #ddd; margin-bottom: 10px; user-select: all;">[lotemaster_map id="<?php echo $post->ID; ?>"]</code>
            <p>2. Crea una <a href="<?php echo admin_url('post-new.php?post_type=page'); ?>" target="_blank">Página Nueva</a>.</p>
            <p>3. Pega el código dentro.</p>
            <p>4. ¡Listo! Tus clientes verán el mapa interactivo.</p>
        </div>
        <?php
    }

    // --- MEJORADO: Editor con Marcador Visual Arrastrable ---
    public function render_admin_map($post) {
        $map_image_id = get_post_meta($post->ID, '_lmp_image_id', true);
        $map_image_url = $map_image_id ? wp_get_attachment_url($map_image_id) : '';
        $markers = get_post_meta($post->ID, '_lmp_markers', true); 
        
        echo '<input type="hidden" name="lmp_image_id" id="lmp_image_id" value="' . esc_attr($map_image_id) . '">';
        echo '<textarea name="lmp_markers_json" id="lmp_markers_json" style="display:none;">' . esc_textarea($markers) . '</textarea>';
        
        ?>
        <div style="margin-bottom: 15px; display: flex; align-items: center; gap: 10px;">
            <button type="button" class="button button-secondary" id="upload_map_btn"><span class="dashicons dashicons-format-image" style="margin-top:4px;"></span> Cambiar Imagen del Plano</button>
            <span class="description">Haz clic en el mapa para agregar un lote. Arrastra el punto para ajustar la posición.</span>
        </div>

        <div style="display: flex; gap: 20px;">
            <div id="lmp-admin-map" style="flex: 2; height: 600px; background: #f0f0f0; border: 1px solid #ccc; box-shadow: inset 0 0 10px rgba(0,0,0,0.1);"></div>
            
            <div id="lmp-edit-panel" style="flex: 1; background: #fff; border: 1px solid #ddd; padding: 20px; display: none;">
                <h3 style="margin-top:0;">Editar Lote</h3>
                <p style="color: #666; font-size: 12px;">Arrastra el marcador rojo en el mapa para corregir la ubicación.</p>
                
                <label style="display:block; margin-bottom:10px;"><strong>Número de Lote:</strong>
                    <input type="text" id="input_lot_number" class="widefat" placeholder="Ej: 104">
                </label>
                
                <label style="display:block; margin-bottom:10px;"><strong>Precio (Texto):</strong>
                    <input type="text" id="input_price" class="widefat" placeholder="Ej: $150.000.000">
                </label>
                
                <label style="display:block; margin-bottom:10px;"><strong>Área (m²):</strong>
                    <input type="text" id="input_area" class="widefat" placeholder="Ej: 200">
                </label>
                
                <label style="display:block; margin-bottom:15px;"><strong>Estado:</strong>
                    <select id="input_status" class="widefat">
                        <option value="available">🟢 Disponible</option>
                        <option value="reserved">🟠 Apartado</option>
                        <option value="sold">🔴 Vendido</option>
                    </select>
                </label>

                <div style="display: flex; gap: 10px;">
                    <button type="button" class="button button-primary button-large" id="save_point_btn" style="flex:1;">Guardar Lote</button>
                    <button type="button" class="button button-link-delete" id="delete_point_btn" style="color: #a00;">Eliminar</button>
                    <button type="button" class="button button-secondary" id="cancel_edit_btn">Cancelar</button>
                </div>
            </div>
            
            <div id="lmp-instruction-panel" style="flex: 1; background: #f9f9f9; border: 1px dashed #ccc; padding: 20px; display: flex; align-items: center; justify-content: center; text-align: center; color: #888;">
                <p>Selecciona un punto existente o haz clic en el mapa para crear uno nuevo.</p>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var map, imgOverlay;
            var markers = <?php echo $markers ? $markers : '[]'; ?>;
            var tempMarker = null; // Marcador temporal (Ghost)
            var isEditing = false;
            var currentDataIndex = -1; // -1 = Nuevo, >=0 = Editando existente

            // Iconos
            var iconDefault = L.divIcon({ className: 'lmp-marker-saved', iconSize: [20, 20], iconAnchor: [10, 10] });
            var iconTemp = L.divIcon({ className: 'lmp-marker-temp', html: '<span class="dashicons dashicons-move"></span>', iconSize: [30, 30], iconAnchor: [15, 15] });

            map = L.map('lmp-admin-map', { crs: L.CRS.Simple, minZoom: -2 });

            function loadMap(url) {
                if(imgOverlay) map.removeLayer(imgOverlay);
                var img = new Image();
                img.src = url;
                img.onload = function() {
                    var bounds = [[0,0], [this.height, this.width]];
                    imgOverlay = L.imageOverlay(url, bounds).addTo(map);
                    map.fitBounds(bounds);
                    renderMarkers();
                }
            }

            if('<?php echo $map_image_url; ?>') loadMap('<?php echo $map_image_url; ?>');

            // --- 1. CLIC EN MAPA (CREAR NUEVO) ---
            map.on('click', function(e) {
                if(isEditing && currentDataIndex > -1) {
                    alert("Primero guarda o cancela la edición del punto actual.");
                    return;
                }
                startEditing(e.latlng, -1);
            });

            // --- FUNCIÓN CENTRAL DE EDICIÓN ---
            function startEditing(latlng, index) {
                isEditing = true;
                currentDataIndex = index;

                // Limpiar marcador temporal anterior si existe
                if(tempMarker) map.removeLayer(tempMarker);

                // Crear marcador temporal ARRASTRABLE
                tempMarker = L.marker(latlng, {icon: iconTemp, draggable: true}).addTo(map);

                // Mostrar panel
                $('#lmp-instruction-panel').hide();
                $('#lmp-edit-panel').show();

                if(index > -1) {
                    // Cargar datos existentes
                    var data = markers[index];
                    $('#input_lot_number').val(data.number);
                    $('#input_price').val(data.price);
                    $('#input_area').val(data.area);
                    $('#input_status').val(data.status);
                    $('#delete_point_btn').show();
                } else {
                    // Limpiar para nuevo
                    $('#input_lot_number').val('').focus(); // Foco automático
                    $('#input_price').val('');
                    $('#input_area').val('');
                    $('#input_status').val('available');
                    $('#delete_point_btn').hide();
                }

                // Evento Drag del marcador temporal
                tempMarker.on('drag', function(e) {
                    // Aquí podrías actualizar coordenadas en tiempo real si mostraras inputs de lat/lng
                });
            }

            // --- GUARDAR ---
            $('#save_point_btn').click(function() {
                if(!tempMarker) return;
                
                var finalLatLng = tempMarker.getLatLng(); // Obtener posición final (tras arrastrar)
                
                var data = {
                    lat: finalLatLng.lat,
                    lng: finalLatLng.lng,
                    number: $('#input_lot_number').val(),
                    price: $('#input_price').val(),
                    area: $('#input_area').val(),
                    status: $('#input_status').val()
                };

                if(currentDataIndex > -1) {
                    markers[currentDataIndex] = data;
                } else {
                    markers.push(data);
                }

                updateJSON();
                resetEditor();
            });

            // --- BORRAR ---
            $('#delete_point_btn').click(function() {
                if(confirm('¿Estás seguro de borrar este lote?')) {
                    if(currentDataIndex > -1) {
                        markers.splice(currentDataIndex, 1);
                        updateJSON();
                    }
                    resetEditor();
                }
            });

            // --- CANCELAR ---
            $('#cancel_edit_btn').click(function() {
                resetEditor();
            });

            function resetEditor() {
                isEditing = false;
                currentDataIndex = -1;
                if(tempMarker) map.removeLayer(tempMarker);
                $('#lmp-edit-panel').hide();
                $('#lmp-instruction-panel').show();
                renderMarkers();
            }

            function updateJSON() {
                $('#lmp_markers_json').val(JSON.stringify(markers));
            }

            function renderMarkers() {
                // Limpiar todo visualmente
                map.eachLayer(function(layer){
                    if(layer instanceof L.Marker && layer !== tempMarker) map.removeLayer(layer);
                });

                markers.forEach(function(m, index) {
                    var color = m.status === 'available' ? '#4CAF50' : (m.status === 'reserved' ? '#FF9800' : '#F44336');
                    
                    var icon = L.divIcon({
                        className: 'lmp-marker-saved',
                        html: '<div style="background:'+color+'; width:20px; height:20px; border-radius:50%; border:2px solid white; box-shadow:0 1px 3px rgba(0,0,0,0.3); color:white; font-size:10px; text-align:center; line-height:16px;">'+m.number+'</div>',
                        iconSize: [24, 24],
                        iconAnchor: [12, 12]
                    });

                    var marker = L.marker([m.lat, m.lng], {icon: icon}).addTo(map);
                    
                    // Click en marcador existente para editar
                    marker.on('click', function(e) {
                        L.DomEvent.stopPropagation(e);
                        startEditing(e.latlng, index);
                    });
                });
            }

            // Media Uploader
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
            .lmp-marker-temp:active { cursor: grabbing; }
            .lmp-marker-temp span { line-height: 26px; }
            .lmp-marker-saved { cursor: pointer; transition: transform 0.1s; }
            .lmp-marker-saved:hover { transform: scale(1.3); z-index: 999; }
        </style>
        <?php
    }

    public function save_data($post_id) {
        if (isset($_POST['lmp_image_id'])) update_post_meta($post_id, '_lmp_image_id', sanitize_text_field($_POST['lmp_image_id']));
        if (isset($_POST['lmp_markers_json'])) update_post_meta($post_id, '_lmp_markers', $_POST['lmp_markers_json']);
    }

    // 4. Admin Columns para ver el Shortcode
    public function add_shortcode_column($columns) {
        $columns['shortcode'] = 'Shortcode (Copiar y Pegar)';
        return $columns;
    }

    public function display_shortcode_column($column, $post_id) {
        if ($column === 'shortcode') {
            echo '<code style="user-select:all;">[lotemaster_map id="' . $post_id . '"]</code>';
        }
    }

    // 5. Render Frontend (Igual que antes pero optimizado)
    public function render_map($atts) {
        $atts = shortcode_atts(['id' => 0], $atts);
        $post_id = $atts['id'];
        $map_image_id = get_post_meta($post_id, '_lmp_image_id', true);
        $map_image_url = $map_image_id ? wp_get_attachment_url($map_image_id) : '';
        $markers = get_post_meta($post_id, '_lmp_markers', true);
        $logo_url = get_the_post_thumbnail_url($post_id, 'full');
        
        if(!$map_image_url) return '<p style="color:red; border:1px solid red; padding:10px;">Error: El mapa no tiene imagen asignada.</p>';

        ob_start();
        ?>
        <div id="lmp-wrapper-<?php echo $post_id; ?>" style="position:relative;">
            <div id="lmp-front-map-<?php echo $post_id; ?>" style="width: 100%; height: 80vh; background: #eee; border: 1px solid #ddd; border-radius: 8px;"></div>
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

            var map = L.map(mapId, { crs: L.CRS.Simple, minZoom: -2, maxZoom: 2, zoomSnap: 0.5 });
            var img = new Image();
            img.src = '<?php echo $map_image_url; ?>';
            
            img.onload = function() {
                var bounds = [[0,0], [this.height, this.width]];
                L.imageOverlay('<?php echo $map_image_url; ?>', bounds).addTo(map);
                map.fitBounds(bounds);
            }

            var markers = <?php echo $markers ? $markers : '[]'; ?>;
            var modal = document.getElementById("lmp-modal-<?php echo $post_id; ?>");
            var close = modal.querySelector(".lmp-close");
            var form = modal.querySelector(".lmp-quote-form");

            markers.forEach(function(m) {
                var colorClass = 'status-' + m.status;
                var icon = L.divIcon({
                    className: 'lmp-front-marker ' + colorClass,
                    html: '<span>' + m.number + '</span>',
                    iconSize: [28, 28],
                    iconAnchor: [14, 14]
                });

                var marker = L.marker([m.lat, m.lng], {icon: icon}).addTo(map);
                
                marker.on('click', function() {
                    openModal(m);
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
            .lmp-front-marker { background: white; border-radius: 50%; text-align: center; border: 2px solid white; font-weight: bold; line-height: 24px; font-size: 11px; cursor: pointer; box-shadow: 0 2px 5px rgba(0,0,0,0.3); transition: all 0.2s; }
            .lmp-front-marker:hover { transform: scale(1.2); z-index: 1000 !important; }
            .status-available { background: #2ecc71; border-color: #27ae60; color: white; }
            .status-reserved { background: #f39c12; border-color: #d35400; color: white; }
            .status-sold { background: #e74c3c; border-color: #c0392b; color: white; opacity: 0.6; }
            
            .lmp-modal { display: none; position: fixed; z-index: 99999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.7); backdrop-filter: blur(3px); }
            .lmp-modal-content { background-color: #fff; margin: 5% auto; padding: 0; border: 0; width: 90%; max-width: 450px; border-radius: 12px; position: relative; box-shadow: 0 20px 50px rgba(0,0,0,0.3); overflow: hidden; font-family: 'Segoe UI', sans-serif; }
            .lmp-modal-header { background: #f8f9fa; padding: 20px; text-align: center; border-bottom: 1px solid #eee; }
            .lmp-logo { max-height: 60px; margin-bottom: 10px; }
            .lmp-close { position: absolute; right: 15px; top: 10px; font-size: 28px; cursor: pointer; color: #999; }
            .lmp-lot-details { padding: 20px; background: #fff; }
            .lmp-detail-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px dashed #eee; }
            .lmp-quote-form { padding: 20px; background: #f1f1f1; }
            .lmp-quote-form input { width: 100%; padding: 12px; margin-bottom: 10px; border: 1px solid #ddd; border-radius: 6px; }
            .lmp-quote-form button { width: 100%; background: #333; color: white; padding: 15px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; transition: background 0.3s; }
            .lmp-quote-form button:hover { background: #000; }
            .st-available { color: #2ecc71; } .st-reserved { color: #f39c12; } .st-sold { color: #e74c3c; }
        </style>
        <?php
        return ob_get_clean();
    }

    public function handle_quote() {
        $name = sanitize_text_field($_POST['name']);
        $email = sanitize_email($_POST['email']);
        $phone = sanitize_text_field($_POST['phone']);
        $lot_number = sanitize_text_field($_POST['lot_number']);
        $post_id = intval($_POST['project_id']);
        
        $project_name = get_the_title($post_id);
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $message = "<h2>Solicitud para Lote #$lot_number</h2><p><strong>Cliente:</strong> $name</p><p><strong>Tel:</strong> $phone</p>";
        wp_mail($email, "Confirmación: $project_name", $message, $headers); // Simplificado para el ejemplo
        wp_send_json_success(['message' => '¡Recibido!']);
    }
}

new LoteMasterPro();
