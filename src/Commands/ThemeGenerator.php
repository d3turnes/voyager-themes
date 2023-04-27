<?php

namespace D3turnes\VoyagerThemes\Commands;

use Illuminate\Support\Str;
use Illuminate\Console\GeneratorCommand;
use Image;

class ThemeGenerator extends GeneratorCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'make:theme {name}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new bank Theme';

    /**
     * Execute the console command.
     * @return mixed
     */
    public function handle()
    {
        $name = preg_replace('/\s+/', ' ', trim(ucwords(strtolower($this->argument('name')))));

        $this->buildTheme($name);
    }

    /**
     * Build the class with the given name.
     *
     * @param  string  $name
     * @return string
     */
    protected function buildTheme($name)
    {
        $stub = $this->files->get($this->getStub());

        $config = [
            'theme_image' => __DIR__ . '/../../publishable/theme.jpg',
            'font_file' => __DIR__ . '/../../publishable/fonts/ArianaVioleta-dz2K.ttf'
        ];

        $themes_folder = sprintf('%s/%s', config('themes.themes_folder', resource_path('views/themes')), Str::kebab($name));
        if (!file_exists($themes_folder)) {
            mkdir( $themes_folder, '0755' );
            $this->info(sprintf('%s created', $themes_folder));
        }

        $fileName = sprintf('%s/%s.json', $themes_folder, Str::kebab($name));
        if (!file_exists($fileName)) {

            file_put_contents($fileName, str_replace('DummyThemeName', $name, $stub));

            $this->info(sprintf('%s created ok.', $fileName));
        }


        $img = config('themes.theme_image', $config['theme_image']);

        if (!file_exists($img)) {
            $img = $config['theme_image'];
        }

        $img = Image::make($img);
        $width = $img->width();
        $height = $img->height();


        // draw rectangle
        if (config('themes.rectangle', true)) {
            $img->rectangle(100, 300, $width-100, ($height/2)/2+260, function($draw) {
                // $draw->background('rgba(0,0,0,0.5)');
                $draw->border(5, '#fff');
            });
        }

        $default = [
            'file' => config('themes.text.file', $config['font_file']),
            'size' => config('themes.text.size', 120),
            'color' => config('themes.text.color', [255, 255, 255, 0.5]),
            'align' => config('themes.text.align', 'center'),
            'valign' => config('themes.text.valign', 'center'),
            'angle' => config('themes.text.angle', 0)
        ];

        if (!file_exists($default['file'])) {
            $default['file'] = $config['font_file'];
        }

        // draw text
        $img->text($name, $width/2, $height/2, function($font) use ($default) {
            $font->file($default['file']);
            $font->size($default['size']);
            $font->color($default['color']);
            $font->align($default['align']);
            $font->valign($default['valign']);
            $font->angle($default['angle']);
        });

        // resize & save image
        $img->resize(600, null, function ($constraint) {
            $constraint->aspectRatio();
        })->save( sprintf('%s/%s.jpg', $themes_folder,  Str::kebab($name)), 85);

    }

    /**
     * Get the stub file for the generator.
     *
     * @return string
     */
    protected function getStub()
    {
        return __DIR__.'/../stubs/theme.stub';
    }
}
