<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProveedoresUsuarios4NSeeder extends Seeder
{
    public function run(): void
    {
        $data = <<<'ROWS'
4N RM|0|N/A
4N RM|4N-Demo|N/A
4N RM|Aime lara|77390761-7
4N RM|Alexia Bao|77390761-7
4N RM|Alvaro Conejeros|77946757-0
4N RM|Alvaro Octavio Robles Gallegos|76716142-5
4N RM|Alvaro Patricio Conejeros Michellod|77946757-0
4N RM|Carlos Alberto Riquelme Herrera|N/A
4N RM|Carlos Ibar|77390761-7
4N RM|Claudia Emperatriz Reyes Santibañez|77656334-K
4N RM|Claudio Eduardo Gonzalez Grandon|78350442-1
4N RM|Claudio Gonzalez|78350442-1
4N RM|Claudio Patricio Berrios Burgos|11866495-7
4N RM|Claudio Berrios|11866495-7
4N RM|Cristian Andres Rubilar Vargas|15474387-1
4N RM|Cristian Daniel Muñoz Caceres|77390761-7
4N RM|Daniel Igancio Echague Pardo|77201525-9
4N RM|DS Group SPA|77201525-9
4N RM|Edver Vasquez Lara|N/A
4N RM|Emilia Molina|77390761-7
4N RM|Esteban H|17662544-9
4N RM|Esteban Huenchuquen|17662544-9
4N RM|Gabriel Riquelme|N/A
4N RM|Giuseppe Perini Oyarzun|78138313-9
4N RM|Guillermo Guevara Candia|78464442-1
4N RM|Guillermo Raul Guevara Candia|78464442-1
4N RM|Javiera Olmos|77390761-7
4N RM|Jimmy Olivo|N/A
4N RM|Jocelym Muñoz|77390761-7
4N RM|Jonathan Danilo Reyes Aguilar|77845965-5
4N RM|Jorge Daniel Madrid Bustos|N/A
4N RM|Jorge Luis Tarbes Vergara - Factura|10629309-0
4N RM|Jorge Luis Tarbes Vergara|N/A
4N RM|Jose Alex Jimenez Moreno|77201525-9
4N RM|Jose Collio Paillao|17850968-3
4N RM|Jose Ignacio Collio Paillao - 1|17850968-3
4N RM|Jose Ignacio Collio Paillao - 2|17850968-3
4N RM|Jose Jimenez|77201525-9
4N RM|Jose Luis Hormazabal Silva|78429685-7
4N RM|Juan David Castro|N/A
4N RM|Juan Eduardo Alfaro Mateluna|N/A
4N RM|Luis Antonio Silva Donoso|N/A
4N RM|Macarena Castro|77201525-9
4N RM|Manuel Isaias Contreras Mardones|78207289-7
4N RM|Maria Galleguillos|77201525-9
4N RM|Maribel Silva Donoso|77201525-9
4N RM|Maribel Elena Silva Donoso|77201525-9
4N RM|Mariela Diaz|N/A
4N RM|Mario Jose Lioi|77390761-7
4N RM|Mario Lioi|77390761-7
4N RM|Mauricio Alejandro Zuñiga Fuentes|78269187-2
4N RM|Mauricio Andres Provoste Cerda|N/A
4N RM|Micsi Vallejos|77390761-7
4N RM|Nelson Enrique Torres Castro|N/A
4N RM|Oscar Javier Neira Carrasco|77390761-7
4N RM|Oscar Neira|77390761-7
4N RM|Patricio Enrique Olea San Martin|77201525-9
4N RM|Patricio Olea San Martin|77201525-9
4N RM|Patricio Yañez|N/A
4N RM|Roberto Avalos|10869353-3
4N RM|Romina Gallardo|77390761-7
4N RM|Romina Jocelyn Gonzalez Estay|78207289-7
4N RM|Sergio Eduardo Vergara Gonzalez|77946757-0
4N RM|Sergio Vergara Gonzalez|77946757-0
4N RM|Transportes Alvaro Patricio Conejeros Michellod EIRL|77946757-0
4N RM|Victor Efrain Torres Figueroa|77390761-7
4N RM|Viviana Soto|77390761-7
4N Temuco|0|N/A
4N Temuco|Claudio Andres Cuevas Aravena|12538127-8
4N Temuco|Javier Salgado|78480951-k
4N Temuco|Mariela Diaz|N/A
4N Temuco|Nicolas Gonalez|N/A
4N Til Til|Mariela Diaz|N/A
4N Troncal Sur 3|Jonatan Jose Pino Lagos|N/A
4N Troncal Sur 3|Raul Antonio Neira Cárdenas|N/A
4N Troncal Sur 3|Sergio Pino|N/A
4N RM|Esnedi Del Carmen Beroiza Beroiza|7220635-5
4N RM|Esteban Andres Huechuquen Vergara|17662544-9
4N RM|Susan Aurora Valenzuela Astudillo|15538591-k
4N RM|Yonny Ñanculeo Marileo|12388671-2
4N RM|Scarlett Arce Villegas|18765018-6
4N RM|David Arellano Morales|6889211-2
4N Temuco|Claudio Cuevas|12538127-8
4N RM|Williams Quispe|78206404-5
4N RM|Nelson Lopez|15536672-9
4N RM|Isaias Contreras|78207289-7
4N RM|Nelson Enrique Torres Castro Reposiciones|10320311-2
ROWS;

        $rows = [];
        foreach (explode("\n", $data) as $line) {
            [$matrix, $courier, $newRut] = array_map('trim', explode('|', $line));
            $rows[] = [
                'RutProveedor' => '77346078-7',
                'ComunaMatriz' => $matrix,
                'NombreRepartidor' => $courier,
                'NuevoRutProveedor' => $newRut,
            ];
        }

        DB::table('Proveedores_usuarios_4N')->upsert(
            $rows,
            ['RutProveedor', 'ComunaMatriz', 'NombreRepartidor'],
            ['NuevoRutProveedor'],
        );
    }
}
