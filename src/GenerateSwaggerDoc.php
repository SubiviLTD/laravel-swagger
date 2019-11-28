<?php

namespace Mtrajano\LaravelSwagger;

use Illuminate\Console\Command;

class GenerateSwaggerDoc extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laravel-swagger:generate
                            {--filter= : Filter to a specific route prefix, such as /api or /v2/api}
                            {--prev=swagger.json : Previous file that should be supplemented with new routes/params(not overwriten)}
                            {--to=swagger.json : File where docs will be stored)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Automatically generates a swagger documentation file for this application';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $config = config('laravel-swagger');

        $old = file_exists(base_path($this->option('prev')))
            ? json_decode(file_get_contents(base_path($this->option('prev'))), true)
            : [];

        $new = (new Generator($config, $this->option('filter') ?: null))->generate();

        $docs = $this->mergeOldAndNew($old, $new);

        $formattedDocs = (new FormatterManager($docs))
            ->setFormat('json')
            ->format();

        $fp = fopen($this->option('to'), 'w');
        fwrite($fp, $formattedDocs);
        fclose($fp);

    }

    private function mergeOldAndNew($old, $new)
    {
        function array_merge_recursive_ex(array $array1, array $array2)
        {
            $merged = $array1;

            foreach ($array2 as $key => & $value) {
                if (is_array($value) && isset($merged[$key]) && is_array($merged[$key])) {
                    $merged[$key] = array_merge_recursive_ex($merged[$key], $value);
                } else if (is_numeric($key)) {
                    if (!in_array($value, $merged)) {
                        $merged[] = $value;
                    }
                } else {
                    $merged[$key] = $value;
                }
            }

            return $merged;
        }

        return array_merge_recursive_ex($old, $new);
    }
}