<?php
    $blocks_id = $block['id'];
    $blocks_class = isset($block['class']) ? $block['class'] : '';
    $anchor = isset($block['anchor']) ? $block['anchor'] : $blocks_id;
?>
<section id="<?php echo $anchor; ?>" class="py-16 bg-secondary-900 single-fighter-last-fights <?php echo $blocks_class; ?>">
<div class="container mx-auto px-4">
    <h2 class="text-3xl font-heading font-bold mb-12 text-center">Poslednje Borbe</h2>
    
    <div class="overflow-x-auto">
        <table class="w-full text-left bg-secondary-800 rounded-lg overflow-hidden">
            <thead class="bg-secondary-700">
                <tr>
                    <th class="py-3 px-4 font-heading">Protivnik</th>
                    <th class="py-3 px-4 font-heading">Događaj</th>
                    <th class="py-3 px-4 font-heading">Datum</th>
                    <th class="py-3 px-4 font-heading">Rezultat</th>
                    <th class="py-3 px-4 font-heading">Metoda</th>
                    <th class="py-3 px-4 font-heading">Runda</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b border-secondary-700 hover:bg-secondary-700 transition">
                    <td class="py-3 px-4 font-bold">John "The Reaper" Smith</td>
                    <td class="py-3 px-4">UFC 285</td>
                    <td class="py-3 px-4">12.03.2023.</td>
                    <td class="py-3 px-4 text-success font-bold">Pobeda</td>
                    <td class="py-3 px-4">KO (Udara)</td>
                    <td class="py-3 px-4">2</td>
                </tr>
                <tr class="border-b border-secondary-700 hover:bg-secondary-700 transition">
                    <td class="py-3 px-4 font-bold">Carlos "El Toro" Rodriguez</td>
                    <td class="py-3 px-4">UFC 281</td>
                    <td class="py-3 px-4">15.11.2022.</td>
                    <td class="py-3 px-4 text-success font-bold">Pobeda</td>
                    <td class="py-3 px-4">Podnesak (Gušenje)</td>
                    <td class="py-3 px-4">3</td>
                </tr>
                <tr class="border-b border-secondary-700 hover:bg-secondary-700 transition">
                    <td class="py-3 px-4 font-bold">Alexander "The Bear" Volkov</td>
                    <td class="py-3 px-4">UFC 276</td>
                    <td class="py-3 px-4">08.07.2022.</td>
                    <td class="py-3 px-4 text-success font-bold">Pobeda</td>
                    <td class="py-3 px-4">KO (Udara)</td>
                    <td class="py-3 px-4">1</td>
                </tr>
                <tr class="border-b border-secondary-700 hover:bg-secondary-700 transition">
                    <td class="py-3 px-4 font-bold">Mike "The Titan" Johnson</td>
                    <td class="py-3 px-4">UFC 270</td>
                    <td class="py-3 px-4">25.03.2022.</td>
                    <td class="py-3 px-4 text-danger font-bold">Poraz</td>
                    <td class="py-3 px-4">Odlučivanje (Jednoglasno)</td>
                    <td class="py-3 px-4">5</td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="text-center mt-8">
        <button class="bg-primary hover:bg-primary-600 px-6 py-3 rounded-lg font-heading font-bold text-button-text transition">
            Prikaži Sve Borbe
        </button>
    </div>
</div>
</section>