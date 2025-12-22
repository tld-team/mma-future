<?php
/**
 * Custom Navigation Walker Classes
 *
 * @package mma-future
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Desktop Navigation Walker with Dropdown Support
 */
class MMA_Desktop_Nav_Walker extends Walker_Nav_Menu {
	
	/**
	 * Start the element output (submenu wrapper)
	 */
	public function start_lvl(&$output, $depth = 0, $args = null) {
		$indent = str_repeat("\t", $depth);
		$output .= "\n{$indent}<ul class=\"absolute left-0 top-full z-50 mt-3 w-56 origin-top-left rounded-lg bg-white shadow-xl ring-1 ring-gray-900/5 hidden opacity-0 transition-all duration-200 ease-out group-hover:block group-hover:opacity-100 list-none\" data-dropdown>\n";
	}

	/**
	 * End the element output (submenu wrapper)
	 */
	public function end_lvl(&$output, $depth = 0, $args = null) {
		$indent = str_repeat("\t", $depth);
		$output .= "{$indent}</ul>\n";
	}

	/**
	 * Start each menu item output
	 */
	public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
		$indent = ($depth) ? str_repeat("\t", $depth) : '';
		
		$classes = empty($item->classes) ? array() : (array) $item->classes;
		$classes[] = 'menu-item-' . $item->ID;
		
		$has_children = in_array('menu-item-has-children', $classes);
		
		$class_names = join(' ', apply_filters('nav_menu_css_class', array_filter($classes), $item, $args));
		
		$output .= $indent . '<li class="' . esc_attr($class_names) . '">';

		if ($depth === 0) {
			// Top level menu items
			if ($has_children) {
				$output .= '<div class="relative group">';
				$output .= '<a href="' . esc_url($item->url) . '" class="nav-link nav-link-dropdown text-base font-heading font-semibold text-heading hover:text-primary no-underline transition-colors py-2 inline-block" aria-expanded="false">';
				$output .= esc_html($item->title);
				$output .= '<svg class="inline-block h-4 w-4 ml-1.5 text-muted group-hover:text-primary transition-all align-middle" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">';
				$output .= '<path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />';
				$output .= '</svg>';
				$output .= '</a>';
			} else {
				$output .= '<a href="' . esc_url($item->url) . '" class="nav-link text-base font-heading font-semibold text-heading hover:text-primary no-underline transition-colors py-2 inline-block">';
				$output .= esc_html($item->title);
				$output .= '</a>';
			}
		} else {
			// Dropdown menu items
			$output .= '<a href="' . esc_url($item->url) . '" class="block px-4 py-2.5 text-sm text-body font-normal hover:bg-secondary-50 hover:text-primary no-underline transition-colors first:rounded-t-lg last:rounded-b-lg">';
			$output .= esc_html($item->title);
			$output .= '</a>';
		}
	}

	/**
	 * End each menu item output
	 */
	public function end_el(&$output, $item, $depth = 0, $args = null) {
		if ($depth === 0) {
			$classes = empty($item->classes) ? array() : (array) $item->classes;
			$has_children = in_array('menu-item-has-children', $classes);
			
			if ($has_children) {
				$output .= '</div>'; // Close .relative.group
			}
		}
		$output .= "</li>\n";
	}
}

/**
 * Mobile Navigation Walker with Accordion Support
 */
class MMA_Mobile_Nav_Walker extends Walker_Nav_Menu {
	
	/**
	 * Start the element output (submenu wrapper)
	 */
	public function start_lvl(&$output, $depth = 0, $args = null) {
		$indent = str_repeat("\t", $depth);
		$output .= "\n{$indent}<ul class=\"mt-2 space-y-1 pl-4 hidden list-none\" data-submenu>\n";
	}

	/**
	 * End the element output (submenu wrapper)
	 */
	public function end_lvl(&$output, $depth = 0, $args = null) {
		$indent = str_repeat("\t", $depth);
		$output .= "{$indent}</ul>\n";
	}

	/**
	 * Start each menu item output
	 */
	public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
		$indent = ($depth) ? str_repeat("\t", $depth) : '';
		
		$classes = empty($item->classes) ? array() : (array) $item->classes;
		$classes[] = 'menu-item-' . $item->ID;
		
		$has_children = in_array('menu-item-has-children', $classes);
		
		$class_names = join(' ', apply_filters('nav_menu_css_class', array_filter($classes), $item, $args));
		
		$output .= $indent . '<li class="' . esc_attr($class_names) . '">';

	if ($has_children && $depth === 0) {
		// Parent item with children - styled exactly as link but functional as button
		$output .= '<button type="button" class="nav-link-mobile nav-link-dropdown-mobile text-sm font-heading font-semibold text-primary hover:text-primary-600 no-underline transition-colors py-2 flex items-center justify-between w-full" data-toggle-submenu>';
		$output .= '<span>' . esc_html($item->title) . '</span>';
		$output .= '<svg class="chevron-icon h-5 w-5 text-primary transition-transform duration-200 flex-shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">';
		$output .= '<path fill-rule="evenodd" d="M5.22 8.22a.75.75 0 0 1 1.06 0L10 11.94l3.72-3.72a.75.75 0 1 1 1.06 1.06l-4.25 4.25a.75.75 0 0 1-1.06 0L5.22 9.28a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd" />';
		$output .= '</svg>';
		$output .= '</button>';
	} else {
		// Regular menu item - identical to desktop style
		$link_class = ($depth === 0) 
			? 'nav-link-mobile text-sm font-heading font-semibold text-heading hover:text-primary no-underline transition-colors py-2 inline-block'
			: 'nav-link-mobile-sub block pl-4 py-2 text-sm font-medium text-body hover:text-primary no-underline transition-colors';
		
		$output .= '<a href="' . esc_url($item->url) . '" class="' . esc_attr($link_class) . '">';
		$output .= esc_html($item->title);
		$output .= '</a>';
	}
	}

	/**
	 * End each menu item output
	 */
	public function end_el(&$output, $item, $depth = 0, $args = null) {
		$output .= "</li>\n";
	}
}
