<?php

namespace App\Http\Controllers;

use App\Models\TechnicianReport;
use App\Models\TechnicianReportImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TechnicianReportController extends Controller
{
    public function store(Request $request, $id)
    {
        $request->validate([
            'report_type' => 'required|in:progress,incident',
            'report_title' => 'required|string|max:255',
            'report_description' => 'required|string',
            'images.*' => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        DB::beginTransaction();

        try {

            $report = TechnicianReport::create([
                'project_id' => $id,
                'technician_id' => 1, // Replace with actual logged-in technician ID
                'report_type' => $request->report_type,
                'report_title' => $request->report_title,
                'report_description' => $request->report_description,
                'report_date' => now()->toDateString(),
            ]);

            if ($request->hasFile('images')) {

                foreach ($request->file('images') as $image) {

                    $path = $image->store('technician-reports', 'public');

                    TechnicianReportImage::create([
                        'technician_report_id' => $report->id,
                        'image_path' => $path,
                    ]);
                }
            }

            DB::commit();

            return back()->with('success', 'Technician report submitted successfully.');

        } catch (\Exception $e) {

            DB::rollBack();

            return back()->with('error', $e->getMessage());
        }
    }

    
}