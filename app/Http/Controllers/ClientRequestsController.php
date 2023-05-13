<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Models\Admin;
use App\Models\User;
use App\Models\Tester;
use App\Models\Hospital;
use App\Models\Commissary;
use App\Models\Client;
use App\Models\Bank;
use App\Models\ClientRequest;

class ClientRequestsController extends Controller
{
    // public function __construct()
    // {
    //     $this->middleware('CheckClient')->except('store')->except('getRequest')->except('donateRequest');
    // }
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        if(Auth()->user()->role == 'Admin'){
            $requests = ClientRequest::where('Way' , 'Home')->get();
        }elseif(Auth()->user()->role == 'Tester'){
            $tester = Tester::where('user_id' , Auth()->user()->id)->first();
            $requests = ClientRequest::where('tester_id' , $tester->id)->where('state' , 'testing')->get();
        }elseif(Auth()->user()->role == 'Commissary'){
            $commissary = Commissary::where('user_id' , Auth()->user()->id)->first();
            $requests = ClientRequest::where('commissary_id' , $commissary->id)->where('state' , 'Pending')->orWhere('state' , 'Delivering')->get();
        }elseif(Auth()->user()->role == 'Hospital'){
            $hospital = Hospital::where('user_id' , Auth()->user()->id)->first();
            $requests = ClientRequest::where('hospital_id' , $hospital->id)->get();
        }
        return view('clientRequests\index'  , [ 'requests' => $requests ]);
    }

    public function getRequest()
    {
        $hospitals = Hospital::all();   
        return view('clientRequests/get' , [ 'hospitals' => $hospitals ]);
    }


    public function donateRequest()
    {
        $hospitals = Hospital::all();
        return view('clientRequests/donate' , [ 'hospitals' => $hospitals ]);

    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {

        $serial = uniqid(); // public (to check the state of the request)
        $code = uniqid();  // QR code (to validate that the delegator go to the right client)
        $attributes['serial'] = $serial;
        $attributes['code'] = $code;
        $attributes['type_of_blood'] = $request->typeOfBlood ;
        $attributes['amount'] = $request->amount;
        $attributes['state'] = 'Pending';
        $attributes['IsOk'] = Null;
        $attributes['note'] = Null;
        $attributes['location'] = $request->location;
        $attributes['client_id'] = Auth()->user()->id;
        $attributes['tester_id'] = Null;
        $attributes['commissary_id'] = Null;
        $attributes['way'] = $request->way;
        $attributes['type_of_request'] = $request->typeOfRequest;
        if( $request->way == 'Home'){
            $attributes['hospital_id'] = Null;
        }else{
            $attributes['hospital_id'] = $request->hospitalId;
        }
        //check the get document
        if ($request->typeOfRequest == 'Request') {
            $name =  $request->document->getClientOriginalName();
            $uploadedFile =  $request->file('document');
            $uploadedFile->move('upload/files/', $name);
            $attributes['document'] = $name;
        }    
        $NewRequest = ClientRequest::create($attributes);
        return view('clientRequests\serial' , ['NewRequest' => $NewRequest]);
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        $request = ClientRequest::where('id' , $id)->first();
        return view('clientRequests/show' , ['request' => $request]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        $commissaries = Commissary::all();
        $testers = Tester::all();
        $request = ClientRequest::where('id' , $id)->first();
        return view('clientRequests/edit' , ['request' => $request , 'commissaries' => $commissaries , 'testers' => $testers ]);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request,$id)
    {
        $cleintRequest = ClientRequest::where('id' , $id)->first();
   
        if( $cleintRequest->way=='Home'){

            if(Auth()->user()->role == 'Admin'){
                if ($cleintRequest->type_of_request == 'Donate'){
                    // if the type of request is donate 
                    $data = $request->only(['tester_id',  'commissary_id']);
                }
                elseif ($cleintRequest->type_of_request == 'Request'){
                    // if the type is get ==> Just Select The commissary_id 
                    $data = $request->only([ 'commissary_id']);
                }
                $cleintRequest->update($data);
            }elseif (Auth()->user()->role == 'Tester') {
                $data = $request->only(['note', 'state' , 'amount' , 'type_of_blood']);
                if ($request->IsOk == 1) {
                    $data['IsOk'] = 1 ;
                }else{
                    $data['IsOk'] = 0 ;
                }
                $cleintRequest->update($data);
                if ($cleintRequest->type_of_request == 'Donate'){
                    if ($request->IsOk == 1) {
                        $bank = Bank::where('hospital_id' , Null)->where('type' , $request->type_of_blood)->first();
                        $newAmount =  $bank->amount + $request->amount ;
                        $bank->update(['amount' => $newAmount]);
                    }
                }
            }elseif (Auth()->user()->role == 'Commissary') {
                //if($request->code == $cleintRequest->code){  }esle{ secret code is wrong }
                $data = $request->only(['note', 'state']);
                if ($cleintRequest->type_of_request == 'Request' && $request->state == 'Success'){
                    $bank = Bank::where('hospital_id' , Null)->where('type' , $cleintRequest->type_of_blood)->first();
                    $newAmount =  $bank->amount - $cleintRequest->amount ;
                    if($newAmount < 0){
                        $data['state'] == 'Pending';
                    }else{
                        $bank->update(['amount' => $newAmount]);
                    }
                }
                $cleintRequest->update($data);
            }
        }
        elseif( $cleintRequest->way=='Hospital'){
                $data = $request->only(['note', 'state' , 'amount' , 'type_of_blood']);
                if ($request->IsOk == 1) {
                    $data['IsOk'] = 1 ;
                }else{
                    $data['IsOk'] = 0 ;
                }
                $cleintRequest->update($data);
                if ($request->IsOk == 1 && $cleintRequest->type_of_request == 'Donate') { 
                    $id = Auth()->user()->id;
                    $hospital = Hospital::where('user_id' , $id)->first();
                    $bank = Bank::where('hospital_id' , $hospital->id)->where('type' , $request->type_of_blood)->first();
                    $newAmount =  $bank->amount + $request->amount ;
                    $bank->update(['amount' => $newAmount]);
                }else{
                    if ($cleintRequest->type_of_request == 'Request' && $cleintRequest->state == 'Success'){
                        $id = Auth()->user()->id;
                        $hospital = Hospital::where('user_id' , $id)->first();
                        $bank = Bank::where('hospital_id' , $hospital->id)->where('type' , $request->type_of_blood)->first();
                        $newAmount =  $bank->amount - $request->amount ;
                        $bank->update(['amount' => $newAmount]);
                    }
                } 
        }
        return redirect('/requests/client')->with('success','Request updated successfully');
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
