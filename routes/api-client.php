<?php

use App\Http\Resources\Client\BannerResource;
use App\Mail\ErrorEmail;
use App\Models\Delivery\DeliveryPickup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

use App\Models\Product;
use App\Http\Resources\Client\Product\ProductResource;

use App\Models\Category;
use App\Http\Resources\Client\CategoryResource;

use App\Models\Coupon;
use App\Http\Resources\Client\CouponResource;

use App\Models\Order;
use App\Http\Resources\Client\OrderResource;
use App\Http\Resources\Client\SettingResource;
use App\Mail\OrderEmail;
use App\Models\Banner;
use App\Models\Payment\PaymentOnline;
use App\Models\Setting;

use Illuminate\Support\Facades\Mail;

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

//

Route::get('/settings', function() {
    return SettingResource::collection( Setting::all() );
});

//

Route::get('/categories', function() {
    return CategoryResource::collection( Category::all() );
});

Route::get('/categories/{id}', function($id) {
    return new CategoryResource( Category::findOrFail($id) );
});

Route::get('/categories/{id}/products', function($id) {
    return ProductResource::collection( Product::where('category_id', $id)->get() );
});

//

Route::get('/products', function() {
    return ProductResource::collection( Product::all() );
});

//

Route::get('/banners', function() {
    return BannerResource::collection( Banner::orderBy('order', 'ASC')->get() );
});

//

Route::get('/coupons/{value}/check', function($value) {
    $coupon = Coupon::where('value', $value)->where('is_active', true)->get();
    if ( count($coupon) ) return new CouponResource($coupon->first());

    return response()->json([ 'data' => null ]);
});

//

Route::post('/order/submit', function(Request $request) {
    $data = $request->all();

    $order = new Order([
        'persons_quantity' => $data['persons_quantity']
    ]);
    $order->save();

    // append products
    foreach ($data['products'] as $product)
        $order->products()->attach($product['id'], [ 'quantity' => $product['quantity'] ]);

    // increase sales count for each product
    foreach($order->products as $product)
        $product->update([ 'sales' => $product->sales + $product->pivot->quantity ]);

    $order->calculate_total($data['coupon']);

    $order->append_delivery($data['delivery']);
    $order->append_payment($data['payment']);

    if (env('APP_DEBUG') === false) {
        Mail::to("info@papakado.ru")->send( new OrderEmail($order) );
    }


    $target_url = env('APP_URL') . '/order/' . $order->id;

    /*********************/
    /* SBIS DELIVERY API */
    /*********************/
    $app_id = env("SBIS_APP_ID");
    $protected_key = env("SBIS_PROTECTED_KEY");
    $service_key = env("SBIS_SERVICE_KEY");

    $sbis_auth_url = "https://online.sbis.ru/oauth/service/";

    $sbis_auth_response = Http::post($sbis_auth_url, [
        "app_client_id" => $app_id,
        "app_secret" => $protected_key,
        "secret_key" => $service_key
    ]);

    if ($sbis_auth_response->successful()) {
        $sbis_token = $sbis_auth_response->json()['token'];

        try {
            $sale_point_id = Http::withHeaders([
                "X-SBISAccessToken" => $sbis_token
            ])->get("https://api.sbis.ru/retail/point/list")
                ->json()["salesPoints"][0]["id"];

            $price_list_id = 8; // In the future, make http request to get needed price list. Hardcode is fine for now.

            $nomenclatures = [];
            foreach ($order->products as $product) {
                $product_name = $product->name;
                $nomenclature_list_url =
                    "https://api.sbis.ru/retail/nomenclature/list?" .
                    "pointId=$sale_point_id&" .
                    "priceListId=$price_list_id&" .
                    "searchString=$product_name";

                $found_nomenclatures = Http::withHeaders([
                    "X-SBISAccessToken" => $sbis_token
                ])->get($nomenclature_list_url)->json()["nomenclatures"];

                foreach ($found_nomenclatures as $nomenclature) {
                    if ($nomenclature['id'] !== null) {
                        array_push($nomenclatures, [
                            "id" => $nomenclature['id'],
                            "count" => $product->pivot->quantity,
                            "priceListId" => $price_list_id
                        ]);
                        break;
                    }
                }
            }

            $isPickup = get_class($order->delivery->delivered) === DeliveryPickup::class;
            $delivery_data = $order->delivery->GetDeliveredClientResourceAttribute();

            $city = "Санкт-Петербург";
            $street = $delivery_data->street;
            $house = $delivery_data->house;

            $isOnlinePayment = get_class($order->payment->paid) === PaymentOnline::class;

            $delivery = [
                "isPickup" => false
            ];

            if ($isOnlinePayment) {
                $delivery['paymentType'] = "online";
                $delivery['shopURL'] = env('APP_URL');
                $delivery['successURL'] = env('APP_URL');
                $delivery['errorURL'] = env('APP_URL');
            }

            if ($isPickup) {
                $delivery['isPickup'] = true;
                $delivery['addressFull'] = "$city $street $house";
                $delivery['addressJSON'] = json_encode([
                    "City" => $city,
                    "Street" => $street,
                    "HouseNum" => $house
                ]);
            }

            $sbis_delivery_response = Http::withHeaders([
                "X-SBISAccessToken" => $sbis_token
            ])->post('https://api.sbis.ru/retail/order/create', [
                "product" => "delivery",
                "pointId" => $sale_point_id,
                "comment" => $order->delivery->comment ?: "",
                "customer" => [
                    "name" => $order->delivery->name,
                    "phone" => $order->delivery->phone
                ],
                "datetime" => date('Y-m-d H:i:s'),
                "nomenclatures" => $nomenclatures,
                "delivery" => $delivery
            ]);

            throw_if($sbis_delivery_response->failed());
        } catch (Exception $e) {
            if (env('APP_DEBUG') === false) {
                Mail::to("info@papakado.ru")->send(new ErrorEmail($e));
            }
        } finally {
            $exit_url = "https://online.sbis.ru/oauth/service/";
            Http::withHeaders([
                "X-SBISAccessToken" => $sbis_token
            ])->post($exit_url, [
                "event" => "exit",
                "token" => $sbis_token
            ]);
        }
    }
    /*********************/
    /* END OF            */
    /* SBIS DELIVERY API */
    /*********************/

    if ( get_class($order->payment->paid) === PaymentOnline::class )
    {
        $url = 'https://3dsec.sberbank.ru/payment/rest/register.do';
        $data = [
            'userName' => env('SBER_API_NAME'),
            'password' => env('SBER_API_PASSWORD'),
            'orderNumber' => $order->id,
            'amount' => $order->total * 100,
            'currency' => 643,
            'returnUrl' => $target_url
        ];

        $options = [
            'http' => [
                'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                'method'  => 'POST',
                'content' => http_build_query($data)
            ]
        ];
        $context  = stream_context_create($options);

        $result = file_get_contents($url, false, $context);
        $result_data = json_decode($result, true);

        return response()->json([
            'data' => [
                'redirect_url' => $result_data['formUrl'],
                'external' => true
            ]
        ]);
    }

    return response()->json([
        'data' => [
            'redirect_url' => '/order/' . $order->id,
            'external' => false
        ]
    ]);
});

//

Route::get('/orders/{id}', function ($id) {
    $order = Order::find($id);
    return new OrderResource($order);
});

Route::post('/orders/{id}/payment/online/check-status', function(Request $request, $id) {
    $gatewey_order_id = $request->input('order_id');

    $url = 'https://3dsec.sberbank.ru/payment/rest/getOrderStatusExtended.do';
    $data = [
        'userName' => env('SBER_API_NAME'),
        'password' => env('SBER_API_PASSWORD'),
        'orderId' => $gatewey_order_id,
    ];

    $options = [
        'http' => [
            'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
            'method'  => 'POST',
            'content' => http_build_query($data)
        ]
    ];
    $context  = stream_context_create($options);

    $result = file_get_contents($url, false, $context);
    $result_data = json_decode($result, true);

    $order = Order::find($id);
    $order->payment->paid->update([
        'status' => $result_data['orderStatus'] === 2 ? 'оплачено' : 'не оплачено'
    ]);
});
