<?php
$blocks_id = $block['id'];
$blocks_class = isset($block['class']) ? $block['class'] : '';
$anchor = isset($block['anchor']) ? $block['anchor'] : $blocks_id;
?>

<section id="<?php echo $anchor; ?>" class="py-16 bg-secondary-800 single-fighter-detail <?php echo $blocks_class; ?>">
    <div class="container mx-auto px-4">
        <h2 class="text-3xl font-heading font-bold mb-12 text-center">Detalji o Borcu</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-8">
            <!-- Lični podaci -->
            <div class="bg-secondary-700 p-6 rounded-lg">
                <h3 class="text-xl font-heading font-bold mb-4 text-primary flex items-center">
                    <i class="fas fa-user mr-2"></i> Lični Podaci
                </h3>
                <ul class="space-y-3">
                    <li class="flex justify-between">
                        <span class="text-muted">Nadimak:</span>
                        <span class="font-bold">Haker</span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-muted">Datum rođenja:</span>
                        <span class="font-bold">15.03.1990.</span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-muted">Visina:</span>
                        <span class="font-bold">191 cm</span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-muted">Težina:</span>
                        <span class="font-bold">112 kg</span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-muted">Zemlja:</span>
                        <span class="font-bold">Srbija</span>
                    </li>
                </ul>
            </div>

            <!-- Borilački stil -->
            <div class="bg-secondary-700 p-6 rounded-lg">
                <h3 class="text-xl font-heading font-bold mb-4 text-primary flex items-center">
                    <i class="fas fa-fist-raised mr-2"></i> Borilački Stil
                </h3>
                <ul class="space-y-3">
                    <li class="flex justify-between">
                        <span class="text-muted">Primarni stil:</span>
                        <span class="font-bold">Boks</span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-muted">Sekundarni stil:</span>
                        <span class="font-bold">Brazilski Džiu-džicu</span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-muted">Raspon ruku:</span>
                        <span class="font-bold">203 cm</span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-muted">Tim:</span>
                        <span class="font-bold">Elite MMA</span>
                    </li>
                    <li class="flex justify-between">
                        <span class="text-muted">Trener:</span>
                        <span class="font-bold">Nikola Jovanović</span>
                    </li>
                </ul>
            </div>

            <!-- Trenutni rekord -->
            <div class="bg-secondary-700 p-6 rounded-lg">
                <h3 class="text-xl font-heading font-bold mb-4 text-primary flex items-center">
                    <i class="fas fa-chart-line mr-2"></i> Trenutni Rekord
                </h3>
                <div class="space-y-4">
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-muted">Ukupno borbi:</span>
                            <span class="font-bold">27</span>
                        </div>
                        <div class="w-full bg-secondary-600 rounded-full h-2">
                            <div class="bg-primary h-2 rounded-full" style="width: 100%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-muted">Pobede:</span>
                            <span class="font-bold">24 (89%)</span>
                        </div>
                        <div class="w-full bg-secondary-600 rounded-full h-2">
                            <div class="bg-success h-2 rounded-full" style="width: 89%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex justify-between mb-1">
                            <span class="text-muted">Nokauti:</span>
                            <span class="font-bold">18 (75%)</span>
                        </div>
                        <div class="w-full bg-secondary-600 rounded-full h-2">
                            <div class="bg-warning h-2 rounded-full" style="width: 75%"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Nagrade i dostignuća -->
            <div class="bg-secondary-700 p-6 rounded-lg">
                <h3 class="text-xl font-heading font-bold mb-4 text-primary flex items-center">
                    <i class="fas fa-trophy mr-2"></i> Dostignuća
                </h3>
                <ul class="space-y-3">
                    <li class="flex items-start">
                        <i class="fas fa-medal text-warning mt-1 mr-2"></i>
                        <span>UFC Šampion teške kategorije (2022-sada)</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-medal text-secondary-300 mt-1 mr-2"></i>
                        <span>Performance of the Night (5x)</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-medal text-accent mt-1 mr-2"></i>
                        <span>Fight of the Night (3x)</span>
                    </li>
                    <li class="flex items-start">
                        <i class="fas fa-award text-primary mt-1 mr-2"></i>
                        <span>MMA Fighter of the Year 2022</span>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</section>