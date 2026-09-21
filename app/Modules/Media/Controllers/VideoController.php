<?php

namespace App\Modules\Media\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VideoController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'video' => 'nullable|file|mimes:mp4,mov,avi|max:20480',
            'image' => 'nullable|image|max:5120',
            'url' => 'nullable|url',
        ]);

        $videoPath = null;
        $imagePath = null;

        if ($request->hasFile('video')) {
            $videoPath = $request->file('video')->store('media/videos', 'public');
        }

        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('media/images', 'public');
        }

        Video::create([
            'user_id' => auth()->id(),
            'title' => $request->title,
            'path' => $videoPath,
            'image' => $imagePath,
            'url' => $request->url,
        ]);

        return redirect()->route('dashboard')
            ->with('success', 'Contenido agregado correctamente');
    }

    public function destroy($id)
    {
        $video = Video::where('id', $id)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if ($video->path) {
            Storage::disk('public')->delete($video->path);
        }

        if ($video->image) {
            Storage::disk('public')->delete($video->image);
        }

        $video->delete();

        return back()->with('success', 'Eliminado correctamente');
    }
}