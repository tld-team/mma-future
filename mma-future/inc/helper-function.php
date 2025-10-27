;<?php
function tld_list_fighters() {
    // Argumenti upita
    $args = array(
        'post_type'      => 'fighter', // Naziv vašeg CPT-a
        'posts_per_page' => -1,       // -1 znači "uzmi sve postove"
        'post_status'    => 'publish', // Samo objavljeni postovi
        'orderby'        => 'title',   // Sortiraj po naslovu
        'order'          => 'ASC'      // Rastući redosled (A->Ž)
    );

    $query = new WP_Query( $args );

    // Proverava da li ima postova
    if ( $query->have_posts() ) {
        echo '<ul>'; // Počni listu

        // Petlja kroz sve postove
        while ( $query->have_posts() ) {
            $query->the_post();
            echo '<li>' . get_the_title() . '</li>'; // Ispiši naslov u listi
        }

        echo '</ul>'; // Završi listu

        // Resetuj post podatke
        wp_reset_postdata();
    } else {
        echo '<p>Nema pronađenih fightera.</p>';
    }
}

// Ovo je kratkod kod koji možete da koristite u postu/stranici: [spisak_fightera]
add_shortcode( 'list_fighters', 'tld_list_fighters' );