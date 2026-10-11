<?php
namespace MMAF\DataEngine\REST;

use MMAF\DataEngine\Services\PublicReadService;

final class RankingsController extends AbstractRestController {
    public function register_routes(): void {
        register_rest_route(RestServiceProvider::NAMESPACE,'/rankings',array(
            'methods'=>\WP_REST_Server::READABLE,'callback'=>array($this,'get_items'),'permission_callback'=>'__return_true',
            'args'=>array(
                'board'=>array('type'=>'string','default'=>'overall','sanitize_callback'=>'sanitize_key'),
                'q'=>array('type'=>'string','default'=>'','sanitize_callback'=>'sanitize_text_field','maxLength'=>100),
                'page'=>array('type'=>'integer','default'=>1,'minimum'=>1),
                'per_page'=>array('type'=>'integer','default'=>25,'minimum'=>1,'maximum'=>100),
                'sort'=>array('type'=>'string','default'=>'rank','enum'=>array('rank','score')),
                'order'=>array('type'=>'string','default'=>'asc','enum'=>array('asc','desc')),
                'top15'=>array('type'=>'boolean','default'=>false),
                'include_breakdown'=>array('type'=>'boolean','default'=>false),
            ),
        ));
        register_rest_route(RestServiceProvider::NAMESPACE,'/rankings/movers',array(
            'methods'=>\WP_REST_Server::READABLE,'callback'=>array($this,'get_movers'),'permission_callback'=>'__return_true',
            'args'=>array('board'=>array('type'=>'string','default'=>'overall','sanitize_callback'=>'sanitize_key'),'limit'=>array('type'=>'integer','default'=>3,'minimum'=>1,'maximum'=>10),'new_page'=>array('type'=>'integer','minimum'=>1),'new_per_page'=>array('type'=>'integer','minimum'=>1,'maximum'=>100)),
        ));
    }
    public function get_items(\WP_REST_Request $request) { return rest_ensure_response((new PublicReadService())->rankings($request->get_params())); }
    public function get_movers(\WP_REST_Request $request) { return rest_ensure_response((new PublicReadService())->movers((string)$request['board'],(int)$request['limit'],$request->get_params())); }
}
