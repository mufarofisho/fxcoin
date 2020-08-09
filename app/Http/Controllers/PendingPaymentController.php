<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

//Models
use App\Packages;
use App\User;
use App\PendingPayment;
use App\ReferralBonus;
use App\Investment;
use App\MarketPlace;

use App\Http\Resources\InvestmentResource;
//use Laravel\Passport\Client;
use DB;

use App\Http\Resources\PendingPaymentResource;

class PendingPaymentController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $pending_payments = PendingPayment::paginate();
        return PendingPaymentResource::collection($pending_payments);
    }

    public function user_pending_payments()
    {
        $pending_payments = PendingPayment::with('market_place','market_place.user')->paginate();
        return  $pending_payments;
    }
    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $request->validate([
            'balance'              =>'required|max:10|between:0,99.99|',
            'amount'              => 'required|max:10|between:0,99.99|lte:balance',
            'market_place_id'     => 'required|integer',
            'payment_method_id'   => 'required|integer',
            'package_id'          => 'required|integer',
            'comment'             => 'max:1000|nullable',
        ]);
        $user = auth('api')->user();
        try {
            DB::beginTransaction();
            $pending_payment                          = new PendingPayment;
            $pending_payment->amount                  = $request->input('amount');
            $pending_payment->market_place_id         = $request->input('market_place_id');
            $pending_payment->payment_method_id       = $request->input('payment_method_id');
            $pending_payment->package_id              = $request->input('package_id');
            $pending_payment->transaction_code        = Carbon::now()->timestamp. '-' . $user->id;
            $pending_payment->user_id                 = $user->id;
            $pending_payment->comment                 = $request->input('comment');
            $pending_payment->expiration_time         = Carbon::now()->addHours(12);
            $pending_payment->ipAddress               = request()->ip();
            $pending_payment->save();

            $amount =$request->input('amount');
            $balance =$request->input('balance');
            //Get market place and then reduce its value by amount offered by current user
            $market_place = MarketPlace::findOrFail($request->input('market_place_id'));
            if ($amount==$balance) {
                $market_place->balance -= $amount;
                $market_place->status   = 100;
            } else {
                $market_place->balance -= $amount;
            }
            $market_place->save();
            DB::commit();
        } catch (\Exception $e) {
            DB::rollback();
            throw $e;
        }

        return new PendingPaymentResource($pending_payment);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\PendingPayment  $pendingPayment
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $pending_payment = PendingPayment::findOrFail($id);

        //Return single pending_payment as a resource
        return new PendingPaymentResource($pending_payment);
    }

    /**
    * Update the specified resource in storage.
    *
    * @param  \Illuminate\Http\Request  $request
    * @param  \App\PendingPayment  $pendingPayment
    * @return \Illuminate\Http\Response
    */
    public function update(Request $request, PendingPayment $pendingPayment)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\PendingPayment  $pendingPayment
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $pending_payment = PendingPayment::findOrFail($id);

        if ($pending_payment->delete()) {
            return new PendingPaymentResource($pending_payment);
        }
    }

    public function offers()
    {
        $user = auth('api')->user()->id;
        $offers = PendingPayment::where('user_id', '=', $user)->where('status', '=', '2')->paginate();

        //Return single pending_payment as a resource
        return PendingPaymentResource::collection($offers);
    }

    public function make_payment(Request $request)
    {
        $request->validate([
            'id'                  => 'required|integer',
            'comment'             => 'max:1000|nullable',
        ]);
        $make_payment            = PendingPayment::findOrFail($request->id);
        $make_payment->id        = $request->input('id');
        $make_payment->comment   = $request->input('comment');
        $make_payment->status    = 101;
        // $make_payment->pop    = $request->input('pop');
        if ($make_payment->save()) {
            return ['message'=>'Saved Successfully'];
        }
    }

    public function approve_payment(Request $request)
    {
        $request->validate([
            'id'                  => 'required|integer',
        ]);

        //Get pending order with posted id
        $pending_payment = PendingPayment::where('id',$request->input('id'))->first();

        //Get package of the  pending order
        $package    = Packages::findOrFail($pending_payment->package_id)->first();

        //Check if user was referred
        $referrer   = User::findOrFail($pending_payment->user_id)->referrer_id;
        
        $amount = $pending_payment->amount;
        $expected_profit = $amount + ($package->period * $package->daily_interest /100 * $amount);
        $balance = $expected_profit;    

        //Take investment done today by pending order user(buyer)  and with the same package to join with the current one if any
        $investment  = Investment::where('user_id', $pending_payment->user_id)->whereDate('created_at', Carbon::today())->where('package_id', $pending_payment->package_id)->where('status', 101)->first();
        //If there was a valid investment done today of the same package from the same user then update it to have one investment
        if ($investment) {         
            try {
                DB::beginTransaction();

                $investment->expected_profit+=$expected_profit;
                $investment->amount+=$amount;
                $investment->balance+=$balance;
                $investment->save();

                if ($referrer>0) {
                    //Add bonus
                    $referral_bonus                      = new ReferralBonus;
                    $referral_bonus->user_id             = $referrer;
                    $referral_bonus->amount              = $pending_payment->amount*0.1;
                    $investment->referral_bonus()->save($referral_bonus);
                }
                $approve_payment         = PendingPayment::findOrFail($request->id)->update(['status' => 0]);
                DB::commit();
            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }
        } else { // Create new investment
            return "No record created today by this user";
            try {
                DB::beginTransaction();

                //Add Investment
                $investment                          = new Investment;
                $investment->amount                  = $amount;
                $investment->description             = 'Points Purchase';
                $investment->package_id              = $pending_payment->package_id;
                $investment->transaction_code        = Carbon::now()->timestamp. '-' . $pending_payment->user_id;
                $investment->user_id                 = $pending_payment->user_id;
                $investment->comments                = "Paid from the approval by ".auth('api')->user()->username;
                $investment->due_date                = Carbon::now()->addDays($package->period);
                $investment->payment_method_id       = $pending_payment->payment_method_id;
                $investment->currency_id             = 1; //From settings table;
                $investment->ipAddress               = request()->ip();
                $investment->expected_profit         = $expected_profit;
                $investment->balance                 = $balance;
                $investment->save();
                
                if ($referrer>0) {
                    //Add bonus
                    $referral_bonus                      = new ReferralBonus;
                    $referral_bonus->user_id             = $referrer;
                    $referral_bonus->amount              = $request->input('amount')*0.1;
                    $investment->referral_bonus()->save($referral_bonus);
                }
                $approve_payment = PendingPayment::findOrFail($request->id)->update(['status' => 0]);
                DB::commit();
               
                return new InvestmentResource($investment);
            } catch (\Exception $e) {
                DB::rollback();
                throw $e;
            }
        }        
    }
}
