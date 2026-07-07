<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\Technician;

class ProjectController extends Controller
{
    public function index()
    {
        return view('super-admin.projects');
    }
    //Create a new project
        public function create()
    {
        $technicians = DB::table('tbl_technicians')
            ->join('users', 'tbl_technicians.account_id', '=', 'users.id')
            ->select(
                'tbl_technicians.technician_id',
                'users.name',
                'users.role'
            )
            ->get();
        return view('super-admin.createProject', compact('technicians'));
    }

    public function store(Request $request)
    {
        // Logic to store the new project
        return redirect()->route('super-admin.projects');
    }
}
