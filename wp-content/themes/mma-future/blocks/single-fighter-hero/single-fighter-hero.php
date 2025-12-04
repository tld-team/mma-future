
<!-- Hero Sekcija -->
 <?php
$blocks_id = $block['id'];
$blocks_class = isset($block['class']) ? $block['class'] : '';
$anchor = isset($block['anchor']) ? $block['anchor'] : $blocks_id;
?>
<section id="<?php echo $anchor; ?>" class="gradient-bg py-16 md:py-24 single-fighter-hero <?php echo $blocks_class; ?>">
    <div class="container mx-auto px-4">
        <div class="flex flex-col md:flex-row items-center">
            <!-- Slika borca -->
            <div class="md:w-2/5 mb-10 md:mb-0 flex justify-center">
                <div class="fighter-image relative">
                    <?php
                    $img_id = get_post_thumbnail_id();
                    $img_url = wp_get_attachment_image_src($img_id, 'full')[0];
                    ?>
                    <img src="<?php echo $img_url; ?>" 
                            alt="<?php echo get_the_title($img_id); ?>" 
                            class="rounded-lg w-full max-w-md md:max-w-lg">
                    <div class="absolute -bottom-4 -right-4 bg-red-600 text-white px-4 py-2 rounded-lg font-bold">
                        #1 UFC
                    </div>
                </div>
            </div>
            
            <!-- Informacije o borcu -->
            <div class="md:w-3/5 md:pl-12">
                <h1 class="text-4xl md:text-6xl font-bold mb-2">Marko "Haker" Petrović</h1>
                <div class="flex items-center mb-6">
                    <span class="bg-gray-700 px-3 py-1 rounded mr-3">Teška kategorija</span>
                    <div class="flex text-yellow-400">
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star"></i>
                        <i class="fas fa-star-half-alt"></i>
                    </div>
                </div>
                
                <p class="text-gray-300 text-lg mb-8">
                    Jedan od najdominantnijih boraca u teškoj kategoriji sa impresivnim nokaut snagom. 
                    Petrović je osvojio UFC titulu 2022. godine i od tada je uspešno odbranio tri puta.
                    Njegova borilačka filozofija se zasniva na agresivnom stilu i preciznim udarcima.
                </p>
                
                <!-- Statistike -->
                <div class="stats-gradient p-6 rounded-lg mb-8">
                    <h2 class="text-2xl font-bold mb-4">Statistike</h2>
                    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                        <div class="text-center">
                            <div class="text-3xl font-bold text-red-500">24</div>
                            <div class="text-gray-400">Pobede</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold text-red-500">3</div>
                            <div class="text-gray-400">Porazi</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold text-red-500">18</div>
                            <div class="text-gray-400">Nokauta</div>
                        </div>
                        <div class="text-center">
                            <div class="text-3xl font-bold text-red-500">4</div>
                            <div class="text-gray-400">Podnesi</div>
                        </div>
                    </div>
                </div>
                
                <!-- Akcije -->
                <div class="flex flex-wrap gap-4">
                    <button class="!bg-red-600 hover:!bg-red-700 !px-6 !py-3 !rounded-lg !font-bold flex items-center transition btn-primary">
                        <i class="fas fa-play-circle mr-2"></i> Poslednja Borba
                    </button>
                    <button class="!bg-gray-700 hover:!bg-gray-600 !px-6 !py-3 !rounded-lg !font-bold flex items-center transition">
                        <i class="fas fa-trophy mr-2"></i> Dostignuća
                    </button>
                    <button class="!border !border-red-600 !text-red-600 hover:!bg-red-600 hover:!text-white !px-6 !py-3 !rounded-lg !font-bold flex items-center transition">
                        <i class="fas fa-share-alt mr-2"></i> Podeli
                    </button>
                </div>
            </div>
        </div>
    </div>
</section>