<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->string('invitation_card_preview_path')->nullable()->after('invitation_card_path'));

        foreach (DB::table('events')->whereNotNull('invitation_card_path')->get() as $event) {
            $preview = $event->invitation_card_mime === 'image/png'
                ? $event->invitation_card_path
                : $this->createPdfPreview($event->invitation_card_path);
            if ($preview) DB::table('events')->where('id', $event->id)->update(['invitation_card_preview_path' => $preview]);
        }
    }

    public function down(): void
    {
        Schema::table('events', fn (Blueprint $table) => $table->dropColumn('invitation_card_preview_path'));
    }

    private function createPdfPreview(string $cardPath): ?string
    {
        $disk = Storage::disk('local');
        if (! $disk->exists($cardPath)) return null;
        $previewPath = dirname($cardPath).'/'.pathinfo($cardPath, PATHINFO_FILENAME).'-preview.png';
        try {
            $process = new Process(['pdftoppm', '-f', '1', '-singlefile', '-png', '-scale-to', '1400', $disk->path($cardPath), substr($disk->path($previewPath), 0, -4)]);
            $process->setTimeout(30);
            $process->run();
            return $process->isSuccessful() && $disk->exists($previewPath) ? $previewPath : null;
        } catch (\Throwable) {
            return null;
        }
    }
};
