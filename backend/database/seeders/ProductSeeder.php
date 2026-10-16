<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /** 3 menu sesuai desain. Nama, harga, dan kuota sementara: bisa diubah lewat panel admin. */
    public function run(): void
    {
        $products = [
            [
                'slug' => 'molachee-keju-crispy',
                'name' => 'Molachee Keju Crispy',
                'short_name' => 'Molachee',
                'short_description' => 'Aci mozzarella lumer berlapis kulit garing aromatik',
                'description' => 'Makanan ringan dengan cita rasa khas dan tekstur kenyal yang lezat. Disajikan fresh setiap hari dengan perpaduan rasa yang nikmat.',
                'default_note' => 'Topping: Bubuk Keju Asin Manis Gurih',
                'price' => 10000, 'daily_quota' => 30, 'image_path' => 'images/products/molachee.png', 'sort_order' => 1,
            ],
            [
                'slug' => 'cilukba-bungcu',
                'name' => 'Cilukba Bungcu',
                'short_name' => 'Cilukba',
                'short_description' => 'Cilok kuah bakso sapi cincang dengan sambal rawit hijau',
                'description' => 'Camilan gurih dengan tekstur renyah di luar dan lembut di dalam. Cocok dinikmati saat santai bersama teman maupun keluarga.',
                'default_note' => 'Pedas Level: Sedang • Bumbu Ekstra Daun Jeruk',
                'price' => 10000, 'daily_quota' => 40, 'image_path' => 'images/products/cilukba.png', 'sort_order' => 2,
            ],
            [
                'slug' => 'ciweng-pedas-daun-jeruk',
                'name' => 'Ciweng Pedas Daun Jeruk',
                'short_name' => 'Ciweng',
                'short_description' => 'Aci goreng gepeng garing bertabur bumbu jeruk pedas nampol',
                'description' => 'Camilan berbahan dasar aci yang gurih dan renyah. Memiliki rasa khas yang cocok dinikmati sebagai teman bersantai ataupun camilan sehari-hari.',
                'default_note' => 'Pedas Level: Sedang • Bumbu Daun Jeruk',
                'price' => 10000, 'daily_quota' => 35, 'image_path' => 'images/products/ciweng.png', 'sort_order' => 3,
            ],
        ];

        foreach ($products as $data) {
            Product::query()->updateOrCreate(
                ['slug' => $data['slug']],
                $data + ['category' => 'Aci Kering', 'is_active' => true],
            );
        }
    }
}
