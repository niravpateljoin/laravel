<?php

namespace App\Http\Controllers\Api\V1;

use Illuminate\Http\Request; 
use App\Http\Controllers\Controller; 
use Auth;
use App\Models\Cart;
use App\Models\CartItems;
use App\Models\CartAddOn;
use App\Models\Kitchens;
use App\Models\KitchenItems;
use App\Models\KitchenItemVarients;
use Validator;
use App\Http\Helpers\CommonTrait;
use App\Http\Helpers\ResponseTrait;
use Exception;
use DB;
use stdClass;

class CartController extends Controller 
{
    use CommonTrait, ResponseTrait;

    public function __construct() {
        $this->currentUser = Auth::guard('api')->user();
    }
    
    /**
     * Add kitchen item to cart
     * @param Request $request
     * @return type
     */
    public function addItemToCart(Request $request) {
        $validationRules = ['kitchen_id' => 'required|integer|exists:kitchens,id', 'kitchen_item_id' => 'required|integer|exists:kitchen_items,id'];
        
        $validation = Validator::make($request->all(), $validationRules);
        if ($validation->fails()) {
            return $this->validationError($validation);
        }
        //get user id or guest user id
        $user_id = $guest_user_id = null;
        if(!empty($this->currentUser->id)) {
            $user_id = $this->currentUser->id;
        } else {
            $guestUserObj = $this->getGuestUserDetails();
            if(!empty($guestUserObj->id)) {
                $guest_user_id = $guestUserObj->id;
            }
        }
        
        //return if both user id and guest user id not found
        if($guest_user_id == NULL && $user_id == NULL) {
            return $this->error('USER_OR_GUEST_USER_NOT_FOUND');
        }
        
        $kitchenItem = KitchenItems::where(['id' => $request->kitchen_item_id, 'kitchen_id' => $request->kitchen_id])->first();
        if(empty($kitchenItem)) {
            return $this->error('KITCHEN_ITEM_MISMATCH');
        }
        
        //check if item is same, if same then increament else add new
        if($user_id) {
            $cartObj = Cart::where(['user_id' => $user_id])->first();
        } else {
            $cartObj = Cart::where(['guest_user_id' => $guest_user_id])->first();
        }
        DB::beginTransaction();
        if(!empty($cartObj)) {
            $varient_id = isset($request->varient_id) ? $request->varient_id : 0;
            $checkSameItemsInCart = CartItems::select('id', 'quantity')->where(['cart_id' => $cartObj->id, 'kitchen_item_id' => $request->kitchen_item_id, 'varient_id' => $varient_id])->get();
            if(!empty($checkSameItemsInCart)) {
                foreach($checkSameItemsInCart as $checkSameItemInCart) {
                    $checkSameItemAddOn = CartAddOn::select('add_on_id')->where(['cart_item_id' => $checkSameItemInCart->id])->get()->toArray();
                    if(empty($request->add_ons) && empty($checkSameItemAddOn)) {
                        $checkSameItemInCart->quantity += (isset($request->quantity) && $request->quantity > 0) ? $request->quantity : 1;
                        $checkSameItemInCart->save();
                        DB::commit();
                        return $this->success(['cart_id' => $cartObj->id, 'cart_item_id' => $checkSameItemInCart->id], 'ITEM_ADDED_IN_CART');
                    } else {
                        if(!empty($request->add_ons) && !empty($checkSameItemAddOn) && count($checkSameItemAddOn) == count($request->add_ons)) {
                            $sameAddOnFlag = 1;
                            foreach($checkSameItemAddOn as $sameItemAddOn) {
                                if(!in_array($sameItemAddOn['add_on_id'], $request->add_ons)) {
                                   $sameAddOnFlag = 0;
                                    break;
                                }
                            }
                            if($sameAddOnFlag) {
                                $checkSameItemInCart->quantity += (isset($request->quantity) && $request->quantity > 0) ? $request->quantity : 1;
                                $checkSameItemInCart->save();
                                DB::commit();
                                return $this->success(['cart_id' => $cartObj->id, 'cart_item_id' => $checkSameItemInCart->id], 'ITEM_ADDED_IN_CART');
                            }
                        }
                    }
                }
            }
        }
        return $this->addItem($user_id, $guest_user_id, $request);
    }
    
    /**
     * 
     * @param type $user_id
     * @param type $guest_user_id
     * @param type $request
     * @return type
     */
    public function addItem($user_id, $guest_user_id, $request) {
        try {
            if(!empty($request->varient_id)) {
                $KinItmVarsObj = KitchenItemVarients::where(['id' => $request->varient_id, 'kitchen_item_id' => $request->kitchen_item_id])->first();
                if(empty($KinItmVarsObj)) {
                    return $this->error('ITEM_VARIENT_MISMATCH');
                } else {
//                    $input['price'] = $KinItmVarsObj->varient_price;
//                    $input['varient_name'] = $KinItmVarsObj->varient_name;
                }
            }
            
            //cart input vars
            $cartInput['user_id'] = $user_id;
            $cartInput['guest_user_id'] = $guest_user_id;
            $cartInput['kitchen_id'] = $request->kitchen_id;
            $kitchen = Kitchens::where(['id' => $request->kitchen_id])->first();
            if(!empty($kitchen)) {
                $cartInput['company_discount'] = $kitchen->company_discount;
                $cartInput['delivery_fee'] = $kitchen->delivery_fee;
            }
            
            if($user_id) {
                $cartObj = Cart::where(['user_id' => $user_id])->first();
            } else {
                $cartObj = Cart::where(['guest_user_id' => $guest_user_id])->first();
            }
            if(empty($cartObj)) {
                $cartObj = Cart::create($cartInput);
            } else {
                $cartObj->update($cartInput);
            }
            
            //cart items input vars
            $input['cart_id'] = $cartObj->id;
            $input['kitchen_item_id'] = $request->kitchen_item_id;
            $input['varient_id'] = isset($request->varient_id) ? $request->varient_id : 0;
            $input['quantity'] = (isset($request->quantity) && $request->quantity > 0) ? $request->quantity : 1;
            $input['item_instruction'] = isset($request->item_instruction) ? $request->item_instruction : '';
            $cartItemsObj = CartItems::create($input);
            
            //add customization / add ons, if there
            if(!empty($request->add_ons) && count($request->add_ons) > 0) {
                foreach($request->add_ons as $ele) {
                    $this->addAddOn($cartItemsObj->id, $ele);
                }
            }
            DB::commit();
            return $this->success(['cart_id' => $cartObj->id, 'cart_item_id' => $cartItemsObj->id], 'ITEM_ADDED_IN_CART');
        } catch (Exception $ex) {
            DB::rollback();
            return $this->error('ERROR');
        }
    }
    
     /**
     * Add add on
     * @param type $cart_id
     * @param type $add_on_id
     * @return type
     */
    public function addAddOn($cart_item_id, $add_on_id) {
        $input['cart_item_id'] = $cart_item_id;
        $input['add_on_id'] = $add_on_id;
        CartAddOn::create($input);
    }
    
    /**
     * repeat item in cart from cart id
     * @param Request $request
     */
    public function repeatItemInCart(Request $request) {
        $validationRules = ['cart_id' => 'required|integer|exists:cart,id', 'cart_item_id' => 'required|integer|exists:cart_items,id'];
        
        $validation = Validator::make($request->all(), $validationRules);
        if ($validation->fails()) {
            return $this->validationError($validation);
        }
        
        //get user id or guest user id
        $user_id = $guest_user_id = null;
        if(!empty($this->currentUser->id)) {
            $user_id = $this->currentUser->id;
        } else {
            $guestUserObj = $this->getGuestUserDetails();
            if(!empty($guestUserObj->id)) {
                $guest_user_id = $guestUserObj->id;
            }
        }
        
        //return if both user id and guest user id not found
        if($guest_user_id == NULL && $user_id == NULL) {
            return $this->error('USER_OR_GUEST_USER_NOT_FOUND');
        }
        DB::beginTransaction();
        try {
            if($user_id) {
                $cartObj = Cart::where(['user_id' => $user_id, 'id'=> $request->cart_id])->first();
            } else {
                $cartObj = Cart::where(['guest_user_id' => $guest_user_id, 'id'=> $request->cart_id])->first();
            }
            
            // return if, Cart does not belongs to logged in user
            if(empty($cartObj)) {
                return $this->error('USER_CART_MISMATCH');
            }
            
            $cartItemObj = CartItems::where(['id' => $request->cart_item_id])->first();
            if(!empty($cartItemObj)) {
                $cartItemObj->quantity += 1;
                $cartItemObj->save();
                DB::commit();
                return $this->success(['cart_id' => $request->cart_id, 'cart_item_id' => $cartItemObj->id], 'ITEM_ADDED_IN_CART');
            } else {
                return $this->error('CART_ITEM_NOT_FOUND');
            }
            
        } catch (Exception $ex) {
            DB::rollback();
            return $this->error('ERROR');
        }
    }
    
    /**
     * remove items from cart
     * @param Request $request
     * @return type
     */
    public function removeItemFromCart(Request $request) {
        $validationRules = ['cart_id' => 'required|integer|exists:cart,id', 'cart_item_id' => 'required|integer|exists:cart_items,id'];
        
        $validation = Validator::make($request->all(), $validationRules);
        if ($validation->fails()) {
            return $this->validationError($validation);
        }
        //get user id or guest user id
        $user_id = $guest_user_id = null;
        if(!empty($this->currentUser->id)) {
            $user_id = $this->currentUser->id;
        } else {
            $guestUserObj = $this->getGuestUserDetails();
            if(!empty($guestUserObj->id)) {
                $guest_user_id = $guestUserObj->id;
            }
        }
        
        //return if both user id and guest user id not found
        if($guest_user_id == NULL && $user_id == NULL) {
            return $this->error('USER_OR_GUEST_USER_NOT_FOUND');
        }
        
        DB::beginTransaction();
        try {
            if($user_id) {
                $cartObj = Cart::where(['user_id' => $user_id, 'id'=> $request->cart_id])->first();
            } else {
                $cartObj = Cart::where(['guest_user_id' => $guest_user_id, 'id'=> $request->cart_id])->first();
            }
            
            // return if, Cart does not belongs to logged in user
            if(empty($cartObj)) {
                return $this->error('USER_CART_MISMATCH');
            }
            
            $cartItemObj = CartItems::where(['id' => $request->cart_item_id])->first();
            if(!empty($cartItemObj)) {
                if($cartItemObj->quantity <= 1) {
                    $cartItemObj->delete();
                    $this->deleteAddOn($cartItemObj->id);
                } else {
                    $cartItemObj->quantity -= 1;
                    $cartItemObj->save();
                }
                
                //if no item left then set cart to initial stage
                $cartItemObj = CartItems::where(['cart_id' => $request->cart_id])->first();
                if(empty($cartItemObj)) {
                    $this->clearCartOncartId($request->cart_id);
                } else {
                    //remove promocode
                    if($cartObj->promo_code != '') {
                        $this->removePromocodeFromCart($request->cart_id);
                    }
                }
                
                DB::commit();
                return $this->success("", "ITEM_DELETED_FROM_CART");
            } else {
                return $this->error('CART_ITEM_NOT_FOUND');
            }
        } catch (Exception $ex) {
            DB::rollback();
            return $this->error('ERROR');
        }
    }
    
     /**
     * delete add on
     * @param type $cart_id
     * @param type $add_on_id
     * @return type
     */
    public function deleteAddOn($cart_item_id) {
        CartAddOn::where(['cart_item_id' => $cart_item_id])->delete();
    }
    
    /**
     * Clear cart
     * @return type
     */
    public function clearCart() {
        //get user id or guest user id
        $user_id = $guest_user_id = null;
        if(!empty($this->currentUser->id)) {
            $user_id = $this->currentUser->id;
        } else {
            $guestUserObj = $this->getGuestUserDetails();
            if(!empty($guestUserObj->id)) {
                $guest_user_id = $guestUserObj->id;
            }
        }
        
        //return if both user id and guest user id not found
        if($guest_user_id == NULL && $user_id == NULL) {
            return $this->error('USER_OR_GUEST_USER_NOT_FOUND');
        }
        
        try {
            if($user_id) {
                $cartObj = Cart::where(['user_id' => $user_id])->first();
            } else {
                $cartObj = Cart::where(['guest_user_id' => $guest_user_id])->first();
            }
            //call common function to clear cart
            $this->clearCartOncartId($cartObj->id);

            return $this->success("", "CART_CLEARED");
        } catch (Exception $ex) {
            return $this->error('ERROR');
        }
    }
    
    /**
     * Get cart detail
     * @param Request $request
     */
    public function cartDetail(Request $request) {
        DB::beginTransaction();
        try {
            //get user id or guest user id
            $user_id = $guest_user_id = null;
            if(!empty($this->currentUser->id)) {
                $user_id = $this->currentUser->id;
            } else {
                $guestUserObj = $this->getGuestUserDetails();
                if(!empty($guestUserObj->id)) {
                    $guest_user_id = $guestUserObj->id;
                }
            }

            //return if both user id and guest user id not found
            if($guest_user_id == NULL && $user_id == NULL) {
                return $this->error('USER_OR_GUEST_USER_NOT_FOUND');
            }
            
            //create cart if not exists
            if($user_id) {
                $cartObj = Cart::where(['user_id' => $user_id])->first();
            } else {
                $cartObj = Cart::where(['guest_user_id' => $guest_user_id])->first();
            }
            $cartInput['user_id'] = $user_id;
            $cartInput['guest_user_id'] = $guest_user_id;
            if(empty($cartObj)) {
                $cartObj = Cart::create($cartInput);
            }

            $cartArr = Cart::getCartDetails($user_id, $guest_user_id);
            $subResult = $result = [];
            $kitchenDetais = NULL;
            $tax = $grand_total = $total = 0.00;
            foreach($cartArr as $ele) {
                if($ele->id > 0) {
                    $addOnTemp = $cartTemp = [];
                    $cartTemp['cart_item_id'] = $ele->id;
                    $cartTemp['kitchen_item_id'] = $ele->kitchen_item_id;
                    $cartTemp['item_name'] = $ele->item_name;
                    $cartTemp['item_description'] = $ele->description;
                    $cartTemp['item_image'] = $ele->item_image;
                    $cartTemp['item_varient'] = ($ele->varient_name != null) ? $ele->varient_name : "";
                    $cartTemp['item_price'] = $ele->price;
                    $cartTemp['item_quantity'] = $ele->quantity;
                    $cartTemp['total_price'] = 0.00;

                    if(empty($subResult[$ele->id])) {
                        $subResult[$ele->id] = $cartTemp;
                    }

                    if($ele->add_on_name) {
                        $addOnTemp['add_on_category'] = ($ele->category != null) ? $ele->category : "";
                        $addOnTemp['add_on_name'] = ($ele->add_on_name != null) ? $ele->add_on_name : "";
                        $addOnTemp['add_on_price'] = ($ele->add_on_price != null) ? $ele->add_on_price : 0.00;
                        $subResult[$ele->id]['add_ons'][] = $addOnTemp;
                    } else {
                        $subResult[$ele->id]['add_ons'] = [];
                    }
                }
            }

            foreach($subResult as $ele) {
                $price = $ele['item_price'];
                foreach($ele['add_ons'] as $addOn) {
                    $addOnPrice = ($addOn['add_on_price'] != null) ? $addOn['add_on_price'] : 0.00;
                    $price = $price + $addOnPrice;
                }
                $ele['total_price'] = $price * $ele['item_quantity'];
                $total += $ele['total_price'];
                $result[] = $ele;
            }
            
            /**/
            if($user_id) {
                $cartObj = Cart::where(['user_id' => $user_id])->first();
            } else {
                $cartObj = Cart::where(['guest_user_id' => $guest_user_id])->first();
            }
            if($cartObj->company_discount) {
                $discount = $cartObj->discount = ($total / 100) * $cartObj->company_discount;
            } else {
                $discount = $cartObj->discount;
            }

            if($cartObj->kitchen_id) {
                $tax_per = 0;
                $cartObj->total = $total;
                
                $grand_total =  $total - $cartObj->discount;
                if($tax_per) {
                    $tax = ($grand_total / 100) * $tax_per;
                }
                
                $cartObj->tax = $tax = round($tax, 2);
                $grand_total = $grand_total + $cartObj->tax + $cartObj->delivery_fee;
                $cartObj->grand_total = $grand_total = round($grand_total, 2);
                $cartObj->save();

                $kitchenDetais = $this->getKitchenDet($cartObj->kitchen_id, $request->all());
            }
            DB::commit();
            return $this->success(['kitchen_detail' => $kitchenDetais, 'cart_id' => $cartObj->id, 'promo_code' => $cartObj->promo_code,'delivery_fee' => $cartObj->delivery_fee, 'total' => $total, 'discount' => $discount, 'taxes' => $tax, 'grand_total' => $grand_total, 'list' => $result]);
        } catch (Exception $ex) {
            DB::rollback();
            return $this->error('ERROR');
        }
    }
    
    public function getKitchenDet($kitchen_id, $postParams) {
        $kitchenDetailArr = Kitchens::getKitchenDetails($kitchen_id);
        if(!empty($kitchenDetailArr[0]->chef_id)) {
                $arrResult['chef_id'] = $kitchenDetailArr[0]->chef_id;
                $arrResult['chef_name'] = $kitchenDetailArr[0]->chef_name;
                $arrResult['kitchen_id'] = $kitchenDetailArr[0]->kitchen_id;
                $arrResult['per_person_cost'] = $kitchenDetailArr[0]->per_person_cost;
                $arrResult['cuisine_types'] = $kitchenDetailArr[0]->cuisine_types;
                $arrResult['categories'] = $kitchenDetailArr[0]->categories;
                $arrResult['rating'] = $kitchenDetailArr[0]->rating;
                $arrResult['prep_time'] = $kitchenDetailArr[0]->prep_time;
                $arrResult['image'] = $kitchenDetailArr[0]->image;
                $arrResult['pre_order'] = $kitchenDetailArr[0]->pre_order;
                $arrResult['delivery_type'] = $kitchenDetailArr[0]->delivery_type;
                $arrResult['delivery_radius'] = $kitchenDetailArr[0]->delivery_radius;
                $arrResult['accepting_order'] = 0;
                $arrResult['delivery_to_address'] = 0;
                
                $currentHr = date('H'); 
                if($kitchenDetailArr[0]->open == 1 && 
                (($kitchenDetailArr[0]->from_time1 <= $currentHr && $currentHr <=  $kitchenDetailArr[0]->to_time1) ||
                 ($kitchenDetailArr[0]->from_time2 <= $currentHr && $currentHr <=  $kitchenDetailArr[0]->to_time2))) {
                    $arrResult['accepting_order'] = 1;
                }
                
                if(isset($postParams['delivery_latitude']) && isset($postParams['delivery_longitude'])) {
                    if($this->distance($postParams['delivery_latitude'], $postParams['delivery_longitude'], $kitchenDetailArr[0]->latitude, $kitchenDetailArr[0]->longitude, 'M') < $kitchenDetailArr[0]->delivery_radius) {
                        $arrResult['delivery_to_address'] = 1;
                    }
                }

                return $arrResult;
            }
    }
}