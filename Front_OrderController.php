<?php

namespace App\Http\Controllers\Chef;

use App\Http\Controllers\Controller;
use App\Models\Orders;
use App\Models\Kitchens;
use Yajra\DataTables\Facades\DataTables;
use DB;
use Illuminate\Http\Request;
use App\Http\Helpers\CommonTrait;
use App\Http\Helpers\ResponseTrait;
use Illuminate\Routing\UrlGenerator;
use Auth;

class OrderController extends Controller
{
    use CommonTrait, ResponseTrait;
    public function __construct(UrlGenerator $url) {
        $this->url = $url;
        $this->pageMeta['pageName'] = "Order management";
        $this->pageMeta['pageDes'] = "You orders here!!";
        $this->pageMeta['breadCrumbs'] = array("Home" => $this->url->to('/admin/'), "Orders" => "");
    }
    
    
    /**
     * log activity listing page
     * @return type
     */
    public function index() {
         return view('chef.orders.index', ['pageMeta' => $this->pageMeta]);
    }
    
    /**
     * 
     * @param Request $request
     * @return type
     */
    public function ajaxGetOrdersList(Request $request) {
        if(Auth::guard('chef')->check()) {
            $this->logged_in_user_id = Auth::guard('chef')->user()->id;
        }
        $kitchen = Kitchens::where('chef_id', $this->logged_in_user_id)->first();
        $orders = Orders::where(['kitchen_id' => $kitchen->id])->where('status','!=','pending')->select(['orders.*', DB::raw('DATE_FORMAT(orders.created_at, "%d/%m/%Y %H:%i") as created_date')]);

        // Using the Engine Factory
        return Datatables::of($orders)
            ->make(true);
    }
    
    /**
     * Change order status
     * @param Request $request
     * @return type
     */
    public function ajaxUpdateOrderStatus(Request $request) {
        $result = false;
        $result = $this->updateOrderStatus($request->id, $request->status);
        if($result == true) {
            $msg = "ORDER_MARKED_AS_". strtoupper($request->status);
            return $this->success(null, $msg);
        } else {
            return $this->error('ERROR');
        }
    }
    
    /**
     * Get Order Detail
     * @param Request $request
     * @return type
     */
    public function ajaxGetOrderDetail(Request $request) {
        $orderArr = Orders::getOrderItems($request->id);
        $cnt = 0;
        $subResult = $result = [];
        foreach($orderArr as $ele) {
            $addOnTemp = $cartTemp = [];
            $cartTemp['item_name'] = $ele->item_name;
            $cartTemp['status'] = $ele->status;
            $cartTemp['payment_method'] = $ele->payment_method;
            $cartTemp['payment_status'] = $ele->payment_status;
            $cartTemp['varient_name'] = ($ele->varient_name != null) ? $ele->varient_name : "";
            $cartTemp['item_instruction'] = $ele->item_instruction;
            $cartTemp['quantity'] = $ele->quantity;

            if(empty($subResult[$ele->order_item_id])) {
                $subResult[$ele->order_item_id] = $cartTemp;
            }

            if($ele->add_on_name) {
//                $addOnTemp['add_on_category'] = ($ele->category != null) ? $ele->category : "";
                $addOnTemp['add_on_name'] = $ele->add_on_name;
                $subResult[$ele->order_item_id]['add_ons'][] = $addOnTemp;
            } else {
                $subResult[$ele->order_item_id]['add_ons'] = [];
            }
            $cnt++;
        }
        
        foreach($subResult as $ele) {
            $result[] = $ele;
        }
        return $this->success($result, "");
    }
    
    /**
     * Get order detail
     * @param Request $request
     * @return type
     */
    public function orderDetail(Request $request) {
        if(!$request->id) {
            return redirect('/chef/orders/listing');
        }
        $order = [];
        $orderArr = Orders::select(['orders.order_json'])->where(['id' => $request->id])->get()->toArray();
        if(!empty($orderArr) && isset($orderArr[0]['order_json'])) {
            $order = json_decode($orderArr[0]['order_json'], 1);
            $kitchen = Kitchens::where(['id' => $order['kitchen_id']])->first()->toArray();
            $order['kitchen_lat'] = $kitchen['latitude'];
            $order['kitchen_long'] = $kitchen['longitude'];
            $order['kitchen_address'] = $kitchen['address']." ".$kitchen['lane']." ".$kitchen['landmark'];
            
            return view('chef.orders.detail', ['pageMeta' => $this->pageMeta, 'order' => $order]);
        } else {
            return redirect('/chef/orders/listing');
        }
    }
    
    /**
     * Get new//pending order for header notifications
     * @param Request $request
     * @return type
     */
    public function ajaxGetChefPlacedOrder(Request $request) {
        if(Auth::guard('chef')->check()) {
            $this->logged_in_user_id = Auth::guard('chef')->user()->id;
        }
        $result = [];
        $kitchen = Kitchens::where('chef_id', $this->logged_in_user_id)->first();
        $orders = Orders::where(['kitchen_id' => $kitchen->id])->where('status','=','Placed')->select(['orders.id'])->get()->toArray();
        foreach($orders as $order) {
            $result[] = $order['id'];
        }
        return $this->success($result, "");
    }
}
