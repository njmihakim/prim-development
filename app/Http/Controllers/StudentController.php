<?php

namespace App\Http\Controllers;

use App\Exports\StudentExport;
use App\Imports\StudentImport;
use App\Imports\StudentCompare;
use App\Models\ClassModel;
use App\Models\Organization;
use App\Models\Student;
use App\Models\Parents;
use PDF;
use Illuminate\Support\Facades\Validator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use Yajra\DataTables\DataTables;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\View;
use PhpOffice\PhpSpreadsheet\Calculation\MathTrig\Arabic;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Illuminate\Support\Str;
use App\Models\OrganizationRole;
use App\User;
use Illuminate\Database\Eloquent\Model;

class StudentController extends Controller
{
    public function index()
    {
        $userId = Auth::id();
        $organization = $this->getOrganizationByUserId();

        if (Auth::user()->hasRole('Superadmin') || Auth::user()->hasRole('Pentadbir')) {
            $listclass = DB::table('classes')
                ->join('class_organization', 'class_organization.class_id', '=', 'classes.id')
                ->select('classes.id as id', 'classes.nama', 'classes.levelid')
                ->where([
                    ['class_organization.organization_id', $organization[0]->id],
                    ['classes.status', 1]
                ])
                ->orderBy('classes.nama')
                ->get();
        } else {
            $listclass = DB::table('class_organization')
                ->leftJoin('classes', 'class_organization.class_id', '=', 'classes.id')
                ->leftJoin('organization_user', 'class_organization.organ_user_id', 'organization_user.id')
                ->select('classes.id as id', 'classes.nama', 'classes.levelid')
                ->where([
                    ['class_organization.organization_id', $organization[0]->id],
                    ['classes.status', 1],
                    ['organization_user.user_id', $userId]
                ])
                ->orderBy('classes.nama')
                ->get();
        }

        return view("student.index", compact('listclass', 'organization'));
    }

    public function studentexport(Request $request)
    {
        $this->validate($request, [
            'organExport'      =>  'required',
            'classExport'      =>  'required',
        ]);

        // dd($request->kelas, $request->organ);
        return Excel::download(new StudentExport($request->organExport, $request->classExport), 'student.xlsx');
    }

    public function studentimport(Request $request)
    {
        $this->validate($request, [
            'classImport'          =>  'required',
        ]);

        // dd($request->classImport);

        $classID = $request->get('classImport');

        $file       = $request->file('file');
        $namaFile   = $file->getClientOriginalName();
        $file->move('uploads/excel/', $namaFile);
        $public_path = $_SERVER['DOCUMENT_ROOT'];

        $etx = $file->getClientOriginalExtension();
        $formats = ['xls', 'xlsx', 'ods', 'csv'];

        if (!in_array($etx, $formats)) {

            return redirect('/student')->withErrors(['format' => 'Only supports upload .xlsx, .xls files']);
        }
        if($request->compareOption ==true){
            //Excel::import(new StudentCompare($classID), $public_path . '/uploads/excel/' . $namaFile);
            $import = new StudentCompare($classID);
            Excel::import($import, $public_path . '/uploads/excel/' . $namaFile);

            $studentArray = $import->getStudentArray();
            $sameClassStudents= $studentArray['sameClassStudents'];
            $differentClassStudents=$studentArray['differentClassStudents'];
            $differentOrgStudents=$studentArray['differentOrgStudents'];
            $newStudents=$studentArray['newStudents'];
            //dd($differentClassStudents);
            return view('student.compare',compact('sameClassStudents','differentClassStudents','differentOrgStudents','newStudents'));
        }
        else{
            Excel::import(new StudentImport($classID), $public_path . '/uploads/excel/' . $namaFile);
        }
       
        return redirect('/student')->with('success', 'New student has been added successfully');
    }

    public function create()
    {
        //
        $userid     = Auth::id();

        $school = DB::table('organizations')
            ->join('organization_user', 'organization_user.organization_id', '=', 'organizations.id')
            ->select('organizations.id as schoolid')
            ->where('organization_user.user_id', $userid)
            ->first();

        // dd($userid);

        $listclass = DB::table('classes')
            ->join('class_organization', 'class_organization.class_id', '=', 'classes.id')
            ->select('classes.id as id', 'classes.nama', 'classes.levelid')
            ->where([
                ['class_organization.organization_id', $school->schoolid]
            ])
            ->orderBy('classes.nama')
            ->get();

        $organization = $this->getOrganizationByUserId();


        return view('student.add', compact('listclass', 'organization'));
    }

    public function trimString($text){
        $text = trim($text);
        $text= preg_replace('/^\s+|\s+$/u', '',$text);
        return $text;
    }
    public function store(Request $request)
    {
        $classid = $request->get('classes');
        $class = ClassModel::find($classid);
    
        $co = DB::table('class_organization')
            ->select('id', 'organization_id as oid')
            ->where('class_id', $classid)
            ->first();

        $this->validate($request, [
            'name'              =>  'required',
            'classes'           =>  'required',
            'parent_name'       =>  'required',
            'parent_icno'      =>  'required',
        ]);

        $icno=$this->trimString(str_replace('-', '',$request->get('parent_icno')));

        $parentname=$this->trimString(strtoupper($request->get('parent_name')));

        $ifExits = DB::table('users as u')
                    ->leftJoin('organization_user as ou', 'u.id', '=', 'ou.user_id')
                    ->where('u.telno', '=',$icno)
                    ->where('ou.organization_id', $co->oid)
                    ->whereIn('ou.role_id', [5, 6])
                    ->get();
        
        if(count($ifExits) == 0) { // if not teacher or parent

            $newparent = DB::table('users')
                            ->where('telno', '=', $icno)
                            ->first();
            
            // dd($newparent);

            if (empty($newparent)) {
                $this->validate($request, [
                    'parent_icno'      =>  'required|unique:users,telno',
                ]);

                if ($request->parent_email != null)
                {
                    $this->validate($request, [
                        'parent_email'     =>  'required|email|unique:users,email',
                    ]);
                }
    
                $newparent = new Parents([
                    'name'           =>  $parentname,
                    'email'          =>  $request->get('parent_email'),
                    'password'       =>  Hash::make('abc123'),
                    'telno'          =>  $icno,
                    'remember_token' =>  Str::random(40),
                ]);
                $newparent->save();
            }

            // add parent role
            $parentRole = DB::table('organization_user')
                ->where('user_id', $newparent->id)
                ->where('organization_id', $co->oid)
                ->where('role_id', 6)
                ->first();

            if (empty($parentRole)) {
                DB::table('organization_user')->insert([
                    'organization_id'   => $co->oid,
                    'user_id'           => $newparent->id,
                    'role_id'           => 6,
                    'start_date'        => now(),
                    'status'            => 1,
                ]);
            }
        } else {
            $newparent = DB::table('users')
                        ->where('telno', '=', "{$icno}")
                        ->first();

            $parentRole = DB::table('organization_user')
                ->where('user_id', $newparent->id)
                ->where('organization_id', $co->oid)
                ->where('role_id', 6)
                ->first();
                
            // dd($parentRole);

            if(empty($parentRole))
            {
                DB::table('organization_user')->insert([
                    'organization_id'   => $co->oid,
                    'user_id'           => $newparent->id,
                    'role_id'           => 6,
                    'start_date'        => now(),
                    'status'            => 1,
                ]);
            } 
        }   
        $this->assignStudentToParent($newparent->id,$icno,$request,$classid, $ifExits);
        return redirect('/student')->with('success', 'New student has been added successfully');
    }

    public function assignStudentToParent($parentId,$telno,$studentData,$classId, $ifExits){
        
        $co= DB::table('class_organization')
        ->select('id', 'organization_id as oid')
        ->where('class_id', $classId)
        ->first();
        $class = ClassModel::find($classId);

        $ou = DB::table('organization_user')
            ->where('user_id', $parentId)
            ->where('organization_id', $co->oid)
            ->where('role_id', 6)
            ->first();
        // dd($newparent->id);
        $user = User::find($parentId);

        // role parent
        $rolename = OrganizationRole::find(6);
        $user->assignRole($rolename->nama);

        $student = new Student([
            'nama'          =>  empty($studentData->name)? $this->trimString(strtoupper($studentData->studentName)):$this->trimString(strtoupper($studentData->name)),
            // 'icno'          =>  $request->get('icno'),
            'gender'        =>  $studentData->gender,
            //'email'         =>  $studentData->email,
        ]);

        $student->save();
        // 
        DB::table('class_student')->insert([
            'organclass_id'   => $co->id,
            'student_id'      => $student->id,
            'start_date'      => now(),
            'status'          => 1,
        ]);

        $classStu = DB::table('class_student')
            ->where('student_id', $student->id)
            ->first();

        DB::table('organization_user_student')->insert([
            'organization_user_id'  => $ou->id,
            'student_id'            => $student->id
        ]);

        // dd($ou);

        /* 
            - this has to change after all the features have done.
            - delete parent tel column in table `students`
        */
        DB::table('students')
            ->where('id', $student->id)
            ->update(['parent_tel' => $telno]);

        // check fee for new in student
        // check category A fee
        $ifExitsCateA = DB::table('fees_new')
            ->where('category', 'Kategori A')
            ->where('organization_id', $co->oid)
            ->where('status', 1)
            ->get();

        $ifExitsCateBC = DB::table('fees_new')
            ->whereIn('category', ['Kategori B', 'Kategori C'])
            ->where('organization_id', $co->oid)
            ->where('status', 1)
            ->get();

        if (!$ifExitsCateA->isEmpty() && count($ifExits) == 0) {
            foreach ($ifExitsCateA as $kateA) {
                DB::table('fees_new_organization_user')->insert([
                    'status'                    => 'Debt',
                    'fees_new_id'               =>  $kateA->id,
                    'organization_user_id'      =>  $ou->id,
                    'transaction_id'            => NULL
                ]);
            }
        }

        if (!$ifExitsCateBC->isEmpty()) {
            foreach ($ifExitsCateBC as $kateBC) {
                $target = json_decode($kateBC->target);

                if (isset($target->gender)) {
                    if ($target->gender != $studentData->gender) {
                        continue;
                    }
                }

                if ($target->data == "All_Level" || $target->data == $class->levelid) {
                    DB::table('student_fees_new')->insert([
                        'status'            => 'Debt',
                        'fees_id'           =>  $kateBC->id,
                        'class_student_id'  =>  $classStu->id
                    ]);
                } else if (is_array($target->data)) {
                    if (in_array($class->id, $target->data)) {
                        DB::table('student_fees_new')->insert([
                            'status'            => 'Debt',
                            'fees_id'           =>  $kateBC->id,
                            'class_student_id'  =>  $classStu->id
                        ]);
                    }
                }
            }
        }

        $child_organs = DB::table('organizations')
                    ->where('parent_org', $co->oid)
                    ->get();
                    
        foreach ($child_organs as $child_organ) {

            $organ_user_id = DB::table('organization_user')->insertGetId([
                'organization_id'   => $child_organ->id,
                'user_id'           => $parentId,
                'role_id'           => 6,
                'start_date'        => now(),
                'status'            => 1,
            ]);

            $ifExitsCateA = DB::table('fees_new')
                        ->where('category', 'Kategori A')
                        ->where('organization_id', $child_organ->id)
                        ->where('status', 1)
                        ->get();
        
            $ifExitsCateBC = DB::table('fees_new')
                    ->whereIn('category', ['Kategori B', 'Kategori C'])
                    ->where('organization_id', $child_organ->id)
                    ->where('status', 1)
                    ->get();
            
            if(!$ifExitsCateA->isEmpty() && count($ifExits) == 0)
            {
                foreach($ifExitsCateA as $kateA)
                {
                    DB::table('fees_new_organization_user')->insert([
                        'status'                    => 'Debt',
                        'fees_new_id'               =>  $kateA->id,
                        'organization_user_id'      =>  $organ_user_id,
                        'transaction_id'            => NULL
                    ]);
                }
            }

            if(!$ifExitsCateBC->isEmpty())
            {
                foreach($ifExitsCateBC as $kateBC)
                {
                    $target = json_decode($kateBC->target);

                    if(isset($target->gender))
                    {
                        if($target->gender != $request->get('gender'))
                        {
                            continue;
                        }
                    }
                    
                    if($target->data == "All_Level" || $target->data == $class->levelid)
                    {
                        DB::table('student_fees_new')->insert([
                            'status'            => 'Debt',
                            'fees_id'           =>  $kateBC->id,
                            'class_student_id'  =>  $classStu->id
                        ]);
                    }
                    else if(is_array($target->data))
                    {
                        if(in_array($class->id, $target->data))
                        {
                            DB::table('student_fees_new')->insert([
                                'status'            => 'Debt',
                                'fees_id'           =>  $kateBC->id,
                                'class_student_id'  =>  $classStu->id
                            ]);
                        }
                    }

                }
            }
        }
    }

    public function show($id)
    {
        //
    }

    public function edit($id)
    {   $organization = $this->getOrganizationByUserId();
        $organizationId = collect($organization)->pluck('id');

        $student = DB::table('students')
            ->join('class_student', 'class_student.student_id', '=', 'students.id')
            ->join('class_organization', 'class_organization.id', '=', 'class_student.organclass_id')
            ->join('classes', 'classes.id', '=', 'class_organization.class_id')
            ->join('organization_user_student as ous','ous.student_id','students.id')
            ->join('organization_user as ou', 'ou.id','ous.organization_user_id')
            ->join('users as u','u.id','ou.user_id')
            ->select('class_organization.organization_id', 'students.id as id', 'students.nama as studentname', 
                    'students.icno', 'students.gender', 'classes.id as classid', 'classes.nama as classname'
                    , 'class_student.status','students.email','u.name as parentName','u.telno as parentIC')
            ->where([
                ['students.id', $id],
            ])
            ->whereIn('class_organization.organization_id',$organizationId)
            ->orderBy('classes.nama')
            ->first();

        $listclass = DB::table('classes')
            ->join('class_organization', 'class_organization.class_id', '=', 'classes.id')
            ->select('classes.id as id', 'classes.nama', 'classes.levelid')
            ->where([
                ['class_organization.organization_id', $student->organization_id]
            ])
            ->orderBy('classes.nama')
            ->get();

        
        return view('student.update', compact('student', 'organization', 'listclass'));
    }

    public function transferClass($co,$classid,$student){
        //dd($co,$classid,$student);
            $class=DB::table('classes as c')
                    ->where('c.id',$classid)
                    ->first();
            $class_student=DB::table('class_organization as co')
                            ->join('class_student as cs','cs.organclass_id','co.id')
                            ->where('cs.student_id',$student->id)
                            ->where('co.class_id',$student->class_id);
            
            $class_student_details=$class_student->first();
            //dd( $student->id,$student->class_id,$classid);
            $class_student->update([
                                'cs.organclass_id'=>$co->id
                            ]);

            if($class->levelid>0){
                $ifExitsCateBC = DB::table('fees_new')
                ->whereIn('category', ['Kategori B', 'Kategori C'])
                ->where('organization_id', $co->organization_id)
                ->where('status', 1)
                ->get();
    
                $studentHaveFees=DB::table('student_fees_new as sfn')
                                ->join('class_student as cs','cs.id','sfn.class_student_id')
                                ->where('sfn.class_student_id',$class_student_details->id)
                                ->get();
    
                $studentFeesIDs = $studentHaveFees->pluck('fees_id')->toArray();
    
                
                if (!$ifExitsCateBC->isEmpty()) {
                    foreach ($ifExitsCateBC as $kateBC) {
                        $target = json_decode($kateBC->target);
    
                        if (isset($target->gender)) {
                            if ($target->gender != $studentData->gender) {
                                continue;
                            }
                        }
                        
                        if ($target->data == "All_Level" || $target->data == $class->levelid) {
                            if (in_array($kateBC->id, $studentFeesIDs)){
                                continue;
                            }else{
                                DB::table('student_fees_new')->insert([
                                    'status'            => 'Debt',
                                    'fees_id'           =>  $kateBC->id,
                                    'class_student_id'  =>  $class_student_details->id
                                ]);
                            }
                            
                        } else if (is_array($target->data)) {
                            if (in_array($classid, $target->data)) { 
                                if (in_array($kateBC->id, $studentFeesIDs)){
                                    continue;
                                }else{
                                    DB::table('student_fees_new')->insert([
                                        'status'            => 'Debt',
                                        'fees_id'           =>  $kateBC->id,
                                        'class_student_id'  =>  $class_student_details->id
                                    ]);
                                }
                            }
                            else{

                                $Debt = "Debt";
                                $delete = DB::table('student_fees_new as sfn')
                                    ->where([
                                        ['sfn.fees_id', $kateBC->id],
                                        ['sfn.class_student_id', $class_student_details->id],
                                        ['sfn.status', '=', 'Debt'],
                                    ])
                                    ->get()->pluck('id');
                                DB::table('student_fees_new')->whereIn('id',$delete)->delete();

                               
                                  
                            }
                        }
                    }
                }
            }
            
    }
    public function update(Request $request, $id)
    {
        //
        $classid = $request->get('classes');

        $this->validate($request, [
            'name'          =>  'required',
            //'icno'          =>  'required',
            'classes'       =>  'required',
        ]);

        $getOrganizationClass = DB::table('class_organization')
            ->where('class_id', $classid)
            ->first();

        // dd($getOrganizationClass);
        $student = DB::table('students')
            ->join('class_student', 'class_student.student_id', '=', 'students.id')
            ->join('class_organization', 'class_organization.id', '=', 'class_student.organclass_id')
            ->join('classes', 'classes.id', '=', 'class_organization.class_id')
            ->select('students.id as id', 'students.nama as studentname', 'students.icno', 'classes.nama as classname', 'class_student.status','classes.id as class_id')
            ->where([
                ['students.id', $id],
            ]);
        if($student->first()->class_id!=$classid){
            $this->transferClass($getOrganizationClass,$classid,$student->first());
        }
        $student->update(
                [
                    'students.nama' => $request->get('name'),
                    //'students.icno' => $request->get('icno'),
                    'students.gender' => $request->get('gender'),
                    'students.email' => $request->get('email'),
                    //'class_student.organclass_id'    => $getOrganizationClass->id,
                ]
            );

        return redirect()->back()->with('success', 'The data has been updated!')->with('closeTab', true);;
    }

    public function destroy($id)
    {
        //
        $result = DB::table('students')
            ->join('class_student', 'class_student.student_id', '=', 'students.id')
            ->join('class_organization', 'class_organization.id', '=', 'class_student.organclass_id')
            ->join('classes', 'classes.id', '=', 'class_organization.class_id')
            ->select('students.id as id', 'students.nama as studentname', 'students.icno', 'classes.nama as classname', 'class_student.status')
            ->where([
                ['students.id', $id],
            ])
            ->update(
                [
                    'class_student.status' => 0,
                ]
            );


        if ($result) {
            Session::flash('success', 'Murid Berjaya Dipadam');
            return View::make('layouts/flash-messages');
        } else {
            Session::flash('error', 'Murid Gagal Dipadam');
            return View::make('layouts/flash-messages');
        }
    }

    public function getStudentDatatable(Request $request)
    {
        // dd($request->oid);

        if (request()->ajax()) {
            // $oid = $request->oid;

            $classid = $request->classid;

            $hasOrganizaton = $request->hasOrganization;

            $userId = Auth::id();

            if ($classid != '' && !is_null($hasOrganizaton)) {
                $data = DB::table('students')
                    ->join('class_student', 'class_student.student_id', '=', 'students.id')
                    ->join('class_organization', 'class_organization.id', '=', 'class_student.organclass_id')
                    ->join('classes', 'classes.id', '=', 'class_organization.class_id')
                    ->select('students.id as id', 'students.nama as studentname', 'students.icno', 'classes.nama as classname', 'class_student.status')
                    ->where([
                        ['classes.id', $classid],
                        ['class_student.status', 1],
                    ])
                    ->orderBy('students.nama')
                    ->get();

                $table = Datatables::of($data);

                $table->addColumn('status', function ($row) {
                    if ($row->status == '1') {
                        $btn = '<div class="d-flex justify-content-center">';
                        $btn = $btn . '<span class="badge badge-success">Aktif</span></div>';

                        return $btn;
                    } else {
                        $btn = '<div class="d-flex justify-content-center">';
                        $btn = $btn . '<span class="badge badge-danger"> Tidak Aktif </span></div>';

                        return $btn;
                    }
                });

                $table->addColumn('action', function ($row) {
                    $token = csrf_token();
                    $btn = '<div class="d-flex justify-content-center"></div>';
                    $btn = '<div class="d-flex justify-content-center">';
                    $btn = $btn . '<a href="' . route('student.edit', $row->id) . '" class="btn btn-primary m-1" target="_blank">Edit</a>';
                    // $btn = $btn . '<button id="' . $row->id . '" data-token="' . $token . '" class="btn btn-danger m-1">Buang</button>';
                    $btn=$btn.'</div>';
                    return $btn;
                });

                $table->rawColumns(['status', 'action']);
                return $table->make(true);
            }
        }
    }

    public function getOrganizationByUserId()
    {
        $userId = Auth::id();
        if (Auth::user()->hasRole('Superadmin')) {

            return Organization::all();
        } else {
            // user role pentadbir n guru 
            return Organization::whereHas('user', function ($query) use ($userId) {
                $query->where('user_id', $userId)->Where(function ($query) {
                    $query->where('organization_user.role_id', '=', 4)
                        ->Orwhere('organization_user.role_id', '=', 5);
                });
            })->get();
        }
    }

    public function fetchClass(Request $request)
    {
        $userId = Auth::id();
        $organ = Organization::find($request->get('oid'));

        if (Auth::user()->hasRole('Superadmin') || Auth::user()->hasRole('Pentadbir') || Auth::user()->hasRole('Koop Admin')) {
            $list = DB::table('classes')
                ->join('class_organization', 'class_organization.class_id', '=', 'classes.id')
                ->select('classes.id as cid', 'classes.nama as cname')
                ->where([
                    ['class_organization.organization_id', ($organ->parent_org != null ? $organ->parent_org : $organ->id)],
                    ['classes.status', 1]
                ])
                ->orderBy('classes.nama')
                ->get();
        } else {
            $list = DB::table('class_organization')
                ->leftJoin('classes', 'class_organization.class_id', '=', 'classes.id')
                ->leftJoin('organization_user', 'class_organization.organ_user_id', 'organization_user.id')
                ->select('classes.id as cid', 'classes.nama as cname')
                ->where([
                    ['class_organization.organization_id', ($organ->parent_org != null ? $organ->parent_org : $organ->id)],
                    ['classes.status', 1],
                    ['organization_user.user_id', $userId]
                ])
                ->orderBy('classes.nama')
                ->get();
        }
        return response()->json(['success' => $list]);
    }

    public function validateStatus($data){
        $update=false;
        foreach($data as $d){
            $check_debt = DB::table('students')
                                    ->join('class_student', 'class_student.student_id', '=', 'students.id')
                                    ->join('student_fees_new', 'student_fees_new.class_student_id', '=', 'class_student.id')
                                    ->select('students.*')
                                    ->where('class_student.id', $d->csid)
                                    ->where('student_fees_new.status', 'Debt')
                                    ->count();

            if ($check_debt == 0) {
                $update=true;
                DB::table('class_student')
                    ->where('id', $d->csid)
                    ->update(['fees_status' => 'Completed']);

            }
        }
        return $update;
    }

    public function getStudentDatatableFees(Request $request)
    {
        // dd($request->oid);

        if (request()->ajax()) {
            // $oid = $request->oid;
            $classid = $request->classid;
            $orgId =$request->orgId;
            $hasOrganizaton = $request->hasOrganization;

            $userId = Auth::id();

            if ($classid != '' && !is_null($hasOrganizaton)) {
                $data = DB::table('students')
                    ->join('class_student', 'class_student.student_id', '=', 'students.id')
                    ->join('class_organization', 'class_organization.id', '=', 'class_student.organclass_id')
                    ->join('classes', 'classes.id', '=', 'class_organization.class_id')
                    ->select('students.*', 'class_student.fees_status','class_student.id as csid')
                    ->where([
                        ['classes.id', $classid],
                        ['class_student.status', 1],
                    ])
                    ->orderBy('students.nama');
                $update=$this->validateStatus($data->get());
                if($update){
                    $data = DB::table('students')
                    ->join('class_student', 'class_student.student_id', '=', 'students.id')
                    ->join('class_organization', 'class_organization.id', '=', 'class_student.organclass_id')
                    ->join('classes', 'classes.id', '=', 'class_organization.class_id')
                    ->select('students.*', 'class_student.fees_status','class_student.id as csid')
                    ->where([
                        ['classes.id', $classid],
                        ['class_student.status', 1],
                    ])
                    ->orderBy('students.nama');
                }
                $table = Datatables::of($data);

                $table->addColumn('gender', function ($row) {
                    if ($row->gender == 'L') {
                        $btn = '<div class="d-flex justify-content-center">';
                        $btn = $btn . 'Lelaki</div>';

                        return $btn;
                    } else {
                        $btn = '<div class="d-flex justify-content-center">';
                        $btn = $btn . 'Perempuan</div>';

                        return $btn;
                    }
                });

                $table->addColumn('status', function ($row) use ($orgId){

                    $tranB=DB::table('class_student as cs')
                    ->join('student_fees_new as sfn' ,'sfn.class_student_id','cs.id')
                    ->join('fees_transactions_new as ftn','ftn.student_fees_id','sfn.id')
                    ->join('fees_new as fn','fn.id','sfn.fees_id')
                    ->join('transactions as t','t.id','ftn.transactions_id')
                    ->where('fn.organization_id',$orgId)
                    ->where('cs.id',$row->csid)
                    ->where('t.status',"Success")
                    ->select('t.id as transaction_id','t.amount')
                    ->get();
                    
                    $tranA = DB::table('transactions as t')
                    ->leftJoin('fees_new_organization_user as fou', 't.id', 'fou.transaction_id')
                    ->leftJoin('organization_user as ou','ou.id','fou.organization_user_id')
                    ->leftJoin('organization_user_student as ous','ous.organization_user_id','ou.id')
                    ->leftJoin('fees_new as fn', 'fn.id', 'fou.fees_new_id')
                    ->distinct()
                    ->where('ous.student_id', $row->id)
                    ->where('fn.organization_id',$orgId)
                    ->where('t.status', 'Success')
                    ->select('t.id as transaction_id','t.amount')
                    ->get();
    

                $combined = $tranA->concat($tranB);
    
                $unique = $combined->unique('transaction_id');
               
                    if(count($unique)>0) {
                        $btn = '<div class="d-flex  align-items-center flex-column">';
                        foreach($unique as $t){
                           
                            $href = route('receipttest', [ 'transaction_id' => $t->transaction_id ]);
                            $btn = $btn . '<a href ="'.$href.'" target="_blank" >RM '.number_format($t->amount, 2, '.', '').'</a>';
                        }
                        $btn =$btn.'</div>';
                        return $btn;
                    } else {
                        $btn = '<div class="d-flex justify-content-center">';
                        $btn = $btn . '<span class="badge badge-danger"> Belum Bayar </span></div>';
                        return $btn;
                    }
                });



                $table->rawColumns(['gender', 'status']);
                return $table->make(true);
            }

            // dd($data->oid);
        }
    }

    public function generatePDFByClass(Request $request)
    {
        $class_id = $request->class_id;
        $class = ClassModel::where('id', $class_id)->first();

        $get_organization = DB::table('organizations')
            ->join('class_organization', 'class_organization.organization_id', '=', 'organizations.id')
            ->join('classes', 'classes.id', '=', 'class_organization.class_id')
            ->select('organizations.*', 'classes.nama as classname')
            ->where([
                ['classes.id', $class_id],
            ])
            ->first();

        $data = DB::table('students')
            ->join('class_student', 'class_student.student_id', '=', 'students.id')
            ->join('class_organization', 'class_organization.id', '=', 'class_student.organclass_id')
            ->join('classes', 'classes.id', '=', 'class_organization.class_id')
            ->select('students.*', 'class_student.fees_status')
            ->where([
                ['classes.id', $class_id],
                ['class_student.status', 1],
            ])
            ->orderBy('students.nama')
            ->get();

        $pdf = PDF::loadView('fee.report-search.template-pdf', compact('data', 'get_organization'));

        return $pdf->download($class->nama . '.pdf');
    }

    public function compareAddNewStudent(Request $request){
      
        $student = json_decode($request->student);
        //return response()->json(['data'=>$student->parentName]);
        
        $classid=$student->classId;
       
        $co = DB::table('class_organization')
            ->select('id', 'organization_id as oid')
            ->where('class_id', $classid)
            ->first();

       

        $ifExits = DB::table('users as u')
                    ->leftJoin('organization_user as ou', 'u.id', '=', 'ou.user_id')
                    ->where('u.telno', '=', $student->parentTelno)
                    ->where('ou.organization_id', $co->oid)
                    ->whereIn('ou.role_id', [5, 6])
                    ->get();
        
        if(count($ifExits) == 0) { // if not teacher or parent

            $newparent = DB::table('users')
                            ->where('telno', '=', $student->parentTelno)
                            ->first();
            
            // dd($newparent);

            if (empty($newparent)) {
                $validator = Validator::make((array)$student, [
                    'parentTelno' => 'required|unique:users,telno',
                ]);
                
                if ($validator->fails()) {
                    // Handle validation failure, return response, or redirect back with errors
                    // For example:
                    return response()->json(['errors' => $validator->errors()], 422);
                }
    
                $newparent = new Parents([
                    'name'           =>  $student->parentName,
                    //'email'          =>  $request->get('parent_email'),
                    'password'       =>  Hash::make('abc123'),
                    'telno'          =>  $student->parentTelno,
                    'remember_token' =>  Str::random(40),
                ]);
                $newparent->save();
            }

            // add parent role
            $parentRole = DB::table('organization_user')
                ->where('user_id', $newparent->id)
                ->where('organization_id', $co->oid)
                ->where('role_id', 6)
                ->first();

            if (empty($parentRole)) {
                DB::table('organization_user')->insert([
                    'organization_id'   => $co->oid,
                    'user_id'           => $newparent->id,
                    'role_id'           => 6,
                    'start_date'        => now(),
                    'status'            => 1,
                ]);
            }
        } else {
            $newparent = DB::table('users')
                        ->where('telno', '=', "{$student->parentTelno}")
                        ->first();

            $parentRole = DB::table('organization_user')
                ->where('user_id', $newparent->id)
                ->where('organization_id', $co->oid)
                ->where('role_id', 6)
                ->first();
                
            // dd($parentRole);

            if(empty($parentRole))
            {
                DB::table('organization_user')->insert([
                    'organization_id'   => $co->oid,
                    'user_id'           => $newparent->id,
                    'role_id'           => 6,
                    'start_date'        => now(),
                    'status'            => 1,
                ]);
            } 
        }  
        $this->assignStudentToParent($newparent->id,$student->parentTelno,$student,$classid, $ifExits);

    }

    public function compareTransferStudent(Request $request){
        set_time_limit(300);
        $student = json_decode($request->student);

        $co=DB::table('class_organization as co')
            ->where('co.class_id',$student->newClass)
            ->first();
        $class=DB::table('classes as c')
                ->where('c.id',$student->newClass)
                ->first();
        $class_student=DB::table('class_organization as co')
                        ->join('class_student as cs','cs.organclass_id','co.id')
                        ->where('cs.student_id',$student->studentId)
                        ->where('co.class_id',$student->oldClassId);
        
        $class_student_details=$class_student->first();
      
        $class_student->update([
                            'cs.organclass_id'=>$co->id
                        ]);
        
        //if inactive or graduated will not run this 
        if($class->levelid>0){
            $ifExitsCateBC = DB::table('fees_new')
            ->whereIn('category', ['Kategori B', 'Kategori C'])
            ->where('organization_id', $co->organization_id)
            ->where('status', 1)
            ->get();
    
            $studentHaveFees=DB::table('student_fees_new as sfn')
                            ->join('class_student as cs','cs.id','sfn.class_student_id')
                            ->where('sfn.class_student_id',$class_student_details->id)
                            ->get();
    
            $studentFeesIDs = $studentHaveFees->pluck('fees_id')->toArray();
    
            
            if (!$ifExitsCateBC->isEmpty()) {
                foreach ($ifExitsCateBC as $kateBC) {
                    $target = json_decode($kateBC->target);
    
                    if (isset($target->gender)) {
                        if ($target->gender != $studentData->gender) {
                            continue;
                        }
                    }
                    
                    if ($target->data == "All_Level" || $target->data == $class->levelid) {
                        if (in_array($kateBC->id, $studentFeesIDs)){
                            continue;
                        }else{
                            DB::table('student_fees_new')->insert([
                                'status'            => 'Debt',
                                'fees_id'           =>  $kateBC->id,
                                'class_student_id'  =>  $class_student_details->id
                            ]);
                        }
                        
                    } else if (is_array($target->data)) {
                        if (in_array($student->newClass, $target->data)) { 
                            if (in_array($kateBC->id, $studentFeesIDs)){
                                continue;
                            }else{
                                DB::table('student_fees_new')->insert([
                                    'status'            => 'Debt',
                                    'fees_id'           =>  $kateBC->id,
                                    'class_student_id'  =>  $class_student_details->id
                                ]);
                            }
                        }
                        else{
                            $delete=DB::table('student_fees_new as sfn')
                                    ->where('sfn.fees_id',$kateBC->id)
                                    ->where('sfn.class_student_id',$class_student_details->id)
                                    ->where('sfn.status',"Debt")
                                    ->delete();
                            //return response()->json(['data'=>$delete]);  
                        }
                    }
                }
            }
    
        }

        
    }
}
