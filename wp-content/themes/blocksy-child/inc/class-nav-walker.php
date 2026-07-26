<?php
/**
 * 自訂 Nav Walker + Blocksy header 覆寫
 *
 * @package weixiaoacg
 * @subpackage Navigation
 */
defined( 'ABSPATH' ) || exit;

/* ============================================================
   自訂 Nav Walker
   ============================================================ */
class weixiaoacg_Nav_Walker extends Walker_Nav_Menu {
    public function start_el(&$out,$item,$depth=0,$args=null,$id=0) {
        $c    = (array)$item->classes;
        $curr = in_array('current-menu-item',$c)||in_array('current-page-ancestor',$c)||in_array('current-menu-ancestor',$c);
        $drop = in_array('menu-item-has-children',$c);
        $icon = trim($item->description??'');
        $tgt  = $item->target ? ' target="'.esc_attr($item->target).'"' : '';
        if ($depth===0) {
            $out .= '<div class="nav-item'.($drop?' has-dropdown':'').'">';
            $out .= '<a href="'.esc_url($item->url).'" class="nav-link'.($curr?' active':'').'"'.$tgt.($drop?' aria-haspopup="true" aria-expanded="false"':'').'>';
            if ($icon) $out .= '<i class="'.esc_attr($icon).'" aria-hidden="true"></i> ';
            $out .= esc_html($item->title);
            if ($drop) $out .= ' <span class="nav-arrow-wrap" aria-hidden="true"><i class="fa-solid fa-chevron-down nav-arrow"></i></span>';
            $out .= '</a>';
        } else {
            $out .= '<a href="'.esc_url($item->url).'" class="nav-dropdown-item'.($curr?' active':'').'"'.$tgt.'>';
            if ($icon) $out .= '<i class="'.esc_attr($icon).'" aria-hidden="true"></i> ';
            $out .= esc_html($item->title).'</a>';
        }
    }
    public function end_el(&$out,$item,$depth=0,$args=null) { if($depth===0) $out.='</div>'; }
    public function start_lvl(&$out,$depth=0,$args=null) { $out.='<div class="nav-dropdown">'; }
    public function end_lvl(&$out,$depth=0,$args=null)   { $out.='</div>'; }
}

/* ============================================================
   強制使用子主題 header.php
   ============================================================ */
add_filter( 'blocksy:header:is-enabled', '__return_false' );
add_filter( 'blocksy_hero_enabled',      '__return_false' );
