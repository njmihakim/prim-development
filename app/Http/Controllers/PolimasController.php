<?php

namespace App\Http\Controllers;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\DataTables;
use Illuminate\Support\Facades\DB;

use Illuminate\Http\Request;

use function PHPUnit\Framework\isNull;

class PolimasController extends Controller
{
    //
    private $oid = 107;

    public function indexLogin()
    {
        return view('polimas.index');
    }

    public function indexBatch()
    {
        $organization = DB::table('organizations')
            ->where('id', $this->oid)
            ->first();

        return view('polimas.batch', compact('organization'));
    }
    
    public function getBatchDataTable(Request $request)
    {
        if (request()->ajax()) {

            $data = DB::table('classes')
                    ->join('class_organization', 'class_organization.class_id', '=', 'classes.id')
                    ->leftJoin('organization_user', 'class_organization.organ_user_id', 'organization_user.id')
                    ->leftJoin('users', 'organization_user.user_id', 'users.id')
                    ->select('classes.id as cid', 'classes.nama as cnama', 'classes.levelid', 'users.name as guru')
                    ->where([
                        ['class_organization.organization_id', $this->oid],
                        ['classes.status', "1"]
                    ])
                    ->orderBy('classes.nama')
                    ->orderBy('classes.levelid');

            $table = Datatables::of($data);

            $table->addColumn('totalstudent', function ($row) {

                $list_student = DB::table('class_organization')
                    ->join('class_student', 'class_student.organclass_id', '=', 'class_organization.id')
                    ->join('classes', 'classes.id', '=', 'class_organization.class_id')
                    ->join('students', 'students.id', '=', 'class_student.student_id')
                    ->select('classes.nama', DB::raw('COUNT(students.id) as totalstudent'))
                    ->where('classes.id', $row->cid)
                    ->where('class_student.status', 1)
                    ->groupBy('classes.nama')
                    ->first();

                if ($list_student) {
                    $btn = '<div class="d-flex justify-content-center">' . $list_student->totalstudent . '</div>';
                    return $btn;
                } else {
                    $btn = '<div class="d-flex justify-content-center"> 0 </div>';
                    return $btn;
                }
            });

            $table->addColumn('action', function ($row) {
                $token = csrf_token();
                $btn = '<div class="d-flex justify-content-center">';
                $btn = $btn . '<a href="' . route('class.edit', $row->cid) . '" class="btn btn-primary m-1">Edit</a>';
                $btn = $btn . '<button id="' . $row->cid . '" data-token="' . $token . '" class="btn btn-danger m-1">Buang</button></div>';
                return $btn;
            });

            $table->rawColumns(['totalstudent', 'action']);
            return $table->make(true);
        }
    }

    public function indexStudent()
    {
        $organization = DB::table('organizations')
            ->where('id', $this->oid)
            ->first();
        return view('polimas.student', compact('organization'));
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
                    ->select('students.id as id',  'students.nama as studentname', 'students.icno', 'classes.nama as classname', 'class_student.student_id as csid')
                    ->where([
                        ['classes.id', $classid],
                        ['class_student.status', 1],
                    ])
                    ->orderBy('students.nama');

                $table = Datatables::of($data);

                $table->addColumn('status', function ($row) {
                    $isPaid = DB::table('student_fees_new as sfn')
                        ->leftJoin('fees_new as fn', 'fn.id', 'sfn.fees_id')
                        ->where('sfn.status', 'Paid')
                        ->where('sfn.class_student_id', $row->csid)
                        ->select('fn.name')
                        ->first();
                    
                    if (isNull($isPaid))
                    {
                        $btn = '<div class="d-flex justify-content-center">';
                        $btn = $btn . '<span class="badge badge-danger"> Masih Berhutang </span></div>';
                        return $btn;
                    }
                    else
                    {
                        if (strpos($isPaid->name, 'Tidak Hadir'))
                        {
                            $btn = '<div class="d-flex justify-content-center">';
                            $btn = $btn . '<span class="badge badge-success">Tidak Hadir</span></div>';
                            return $btn;
                        }
                        else
                        {
                            $btn = '<div class="d-flex justify-content-center">';
                            $btn = $btn . '<span class="badge badge-success">Hadir</span></div>';
                            return $btn;
                        }
                    }
                });

                $table->addColumn('action', function ($row) {
                    $token = csrf_token();
                    $btn = '<div class="d-flex justify-content-center">';
                    $btn = $btn . '<a href="' . route('student.edit', $row->id) . '" class="btn btn-primary m-1">Edit</a>';
                    $btn = $btn . '<button id="' . $row->id . '" data-token="' . $token . '" class="btn btn-danger m-1">Buang</button></div>';
                    return $btn;
                });

                $table->rawColumns(['status', 'action']);
                return $table->make(true);
            }
        }
    }
}
