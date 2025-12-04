<?php
    $blocks_id = $block['id'];
    $blocks_class = isset($block['class']) ? $block['class'] : '';
    $anchor = isset($block['anchor']) ? $block['anchor'] : $blocks_id;
?>
<section id="<?php echo $anchor; ?>" class="py-16 bg-gray-900 single-fighter-last-fights <?php echo $blocks_class; ?>">
<div class="container mx-auto px-4">
    <h2 class="text-3xl font-bold mb-12 text-center">Poslednje Borbe</h2>
    
    <div class="overflow-x-auto">
        <table class="w-full text-left bg-gray-800 rounded-lg overflow-hidden">
            <thead class="bg-gray-700">
                <tr>
                    <th class="py-3 px-4">Protivnik</th>
                    <th class="py-3 px-4">Događaj</th>
                    <th class="py-3 px-4">Datum</th>
                    <th class="py-3 px-4">Rezultat</th>
                    <th class="py-3 px-4">Metoda</th>
                    <th class="py-3 px-4">Runda</th>
                </tr>
            </thead>
            <tbody>
                <tr class="border-b border-gray-700 hover:bg-gray-750 transition">
                    <td class="py-3 px-4 font-bold">John "The Reaper" Smith</td>
                    <td class="py-3 px-4">UFC 285</td>
                    <td class="py-3 px-4">12.03.2023.</td>
                    <td class="py-3 px-4 text-green-500 font-bold">Pobeda</td>
                    <td class="py-3 px-4">KO (Udara)</td>
                    <td class="py-3 px-4">2</td>
                </tr>
                <tr class="border-b border-gray-700 hover:bg-gray-750 transition">
                    <td class="py-3 px-4 font-bold">Carlos "El Toro" Rodriguez</td>
                    <td class="py-3 px-4">UFC 281</td>
                    <td class="py-3 px-4">15.11.2022.</td>
                    <td class="py-3 px-4 text-green-500 font-bold">Pobeda</td>
                    <td class="py-3 px-4">Podnesak (Gušenje)</td>
                    <td class="py-3 px-4">3</td>
                </tr>
                <tr class="border-b border-gray-700 hover:bg-gray-750 transition">
                    <td class="py-3 px-4 font-bold">Alexander "The Bear" Volkov</td>
                    <td class="py-3 px-4">UFC 276</td>
                    <td class="py-3 px-4">08.07.2022.</td>
                    <td class="py-3 px-4 text-green-500 font-bold">Pobeda</td>
                    <td class="py-3 px-4">KO (Udara)</td>
                    <td class="py-3 px-4">1</td>
                </tr>
                <tr class="border-b border-gray-700 hover:bg-gray-750 transition">
                    <td class="py-3 px-4 font-bold">Mike "The Titan" Johnson</td>
                    <td class="py-3 px-4">UFC 270</td>
                    <td class="py-3 px-4">25.03.2022.</td>
                    <td class="py-3 px-4 text-red-500 font-bold">Poraz</td>
                    <td class="py-3 px-4">Odlučivanje (Jednoglasno)</td>
                    <td class="py-3 px-4">5</td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <div class="text-center mt-8">
        <button class="bg-red-600 hover:bg-red-700 px-6 py-3 rounded-lg font-bold transition">
            Prikaži Sve Borbe
        </button>
    </div>
</div>
</section>