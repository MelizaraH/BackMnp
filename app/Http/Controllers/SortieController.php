<?php

namespace App\Http\Controllers;

use App\Models\Materiel;
use App\Models\Reception;
use App\Models\Sortie;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SortieController extends Controller implements HasMiddleware
{
    public static function middleware()
    {
        return [
            new Middleware('auth:sanctum', except: ['index', 'show'])
        ];
    }

    public function index()
    {
        $receptions = Sortie::with('user')->get();
        return response()->json($receptions);
    }

    public function store(Request $request)
    {
        // Validation des données
        $fields = $request->validate([
            'BonSortie' => 'required|max:20|unique:sorties,BonSortie',
            'CodeMateriel' => 'required|exists:materiels,CodeMateriel',
            'QuantiteSortant' => 'required|integer|min:1',
            'Destinataire' => 'required|max:150',
            'DateSortie' => [
                'required',
                'date',
                'after_or_equal:' . $this->getFirstReceptionDate($request->CodeMateriel), // Validation de la date de sortie
                'before_or_equal:' . now()
            ],
        ], [
            'BonSortie.required' => 'Le bon de sortie est obligatoire.',
            'BonSortie.max' => 'Le bon de sortie ne doit pas dépasser 20 caractères.',
            'BonSortie.unique' => 'Ce bon de sortie existe déjà.',

            'CodeMateriel.required' => 'Le code matériel est obligatoire.',
            'CodeMateriel.exists' => 'Le code matériel spécifié n\'existe pas dans la base de données.',

            'QuantiteSortant.required' => 'La quantité sortante est obligatoire.',
            'QuantiteSortant.integer' => 'La quantité sortante doit être un nombre entier.',
            'QuantiteSortant.min' => 'La quantité sortante doit être supérieure ou égale à 1.',

            'Destinataire.required' => 'Le destinataire est obligatoire.',
            'Destinataire.max' => 'Le destinataire ne doit pas dépasser 150 caractères.',

            'DateSortie.required' => 'La date de sortie est obligatoire.',
            'DateSortie.date' => 'La date de sortie doit être une date valide.',
            'DateSortie.before_or_equal' => 'La date de réception ne peut pas être supérieure à la date d\'aujourd\'hui',
            'DateSortie.after_or_equal' => 'La date de sortie ne peut pas être antérieure à la date de réception.'
        ]);

        // Vérifier si le matériel existe
        $materiel = Materiel::find($fields['CodeMateriel']);
        if ($materiel) {
            // Vérifier si la quantité sortante dépasse le stock disponible
            $stockDisponible = $this->getAvailableStock($fields['CodeMateriel'], $fields['DateSortie']);
            if ($stockDisponible < $fields['QuantiteSortant']) {
                return response()->json(['message' => 'Stock insuffisant, quantité demandée supérieure au stock disponible.'], 400);
            }

            // Soustraire la quantité sortante du stock
            $materiel->Quantite -= $fields['QuantiteSortant'];
            $materiel->save();

            // Créer la sortie
            $sortie = $request->user()->sorties()->create($fields);

            return ['sortie' => $sortie, 'user' => $sortie->user];
        }

        return response()->json(['message' => 'Matériel non trouvé.'], 404);
    }


    private function getFirstReceptionDate($codeMateriel)
    {
        // Récupérer la première date de réception pour ce matériel
        $firstReception = Reception::where('CodeMateriel', $codeMateriel)
            ->orderBy('DateReception', 'asc') // Trier par date croissante
            ->first();

        // Si une réception est trouvée, retourner la date de la première réception
        if ($firstReception) {
            return $firstReception->DateReception;
        }

        // Si aucune réception n'est trouvée, retourner une date lointaine dans le futur pour permettre la validation
        return '9999-12-31';
    }

    private function getAvailableStock($codeMateriel, $dateSortie)
    {
        // Récupérer toutes les réceptions jusqu'à la date de sortie
        $totalReception = Reception::where('CodeMateriel', $codeMateriel)
            ->whereDate('DateReception', '<=', $dateSortie)
            ->sum('QuantiteRecu');

        // Récupérer toutes les sorties jusqu'à la date de sortie
        $totalSortie = Sortie::where('CodeMateriel', $codeMateriel)
            ->whereDate('DateSortie', '<=', $dateSortie)
            ->sum('QuantiteSortant');

        // Calculer le stock disponible
        $stockDisponible = $totalReception - $totalSortie;

        return $stockDisponible;
    }


    public function show($BonSortie)
    {
        $sortie = Sortie::with('user')->where('BonSortie', $BonSortie)->first();
        if ($sortie) {
            return response()->json(['sortie' => $sortie]);
        } else {
            return response()->json(['error' => 'Sortie non trouvée'], 404);
        }
    }

    public function update(Request $request, $BonSortie)
    {
        $sortie = Sortie::where('BonSortie', $BonSortie)->first();

        if (!$sortie) {
            return response()->json(['error' => 'Sortie non trouvée'], 404);
        }

        if (!Gate::allows('update-sortie', $sortie)) {
            return response()->json(['error' => 'Vous n\'avez pas la permission de mettre à jour cette sortie'], 403);
        }

        $fields = $request->validate([
            'BonSortie' => [
                'required',
                'max:20',
                Rule::unique('sorties')->ignore($sortie->BonSortie, 'BonSortie'), // Ignore le BonSortie actuel
            ],
            'CodeMateriel' => 'required|exists:materiels,CodeMateriel',
            'QuantiteSortant' => 'required|integer|min:1',
            'Destinataire' => 'required|max:150',
            'DateSortie' => [
                'required',
                'date',
                'after_or_equal:' . $this->getFirstReceptionDate($request->CodeMateriel), // Validation de la date de sortie
                'before_or_equal:' . now(),
            ],
        ], [
            'BonSortie.required' => 'Le bon de sortie est obligatoire.',
            'BonSortie.max' => 'Le bon de sortie ne doit pas dépasser 20 caractères.',
            'BonSortie.unique' => 'Ce bon de sortie existe déjà.',

            'CodeMateriel.required' => 'Le code matériel est obligatoire.',
            'CodeMateriel.exists' => 'Le code matériel spécifié n\'existe pas dans la base de données.',

            'QuantiteSortant.required' => 'La quantité sortante est obligatoire.',
            'QuantiteSortant.integer' => 'La quantité sortante doit être un nombre entier.',
            'QuantiteSortant.min' => 'La quantité sortante doit être supérieure ou égale à 1.',

            'Destinataire.required' => 'Le destinataire est obligatoire.',
            'Destinataire.max' => 'Le destinataire ne doit pas dépasser 150 caractères.',

            'DateSortie.required' => 'La date de sortie est obligatoire.',
            'DateSortie.date' => 'La date de sortie doit être une date valide.',
            'DateSortie.before_or_equal' => 'La date de réception ne peut pas être supérieure à la date d\'aujourd\'hui',
            'DateSortie.after_or_equal' => 'La date de sortie ne peut pas être antérieure à la date de réception.'
        ]);

        $oldQuantiteSortant = $sortie->QuantiteSortant;
        $materiel = Materiel::find($fields['CodeMateriel']);

        if ($materiel) {
            // Calcul de la quantité totale de réceptions jusqu'à la date de la sortie modifiée
            $totalReceptionQuantite = Reception::where('CodeMateriel', $fields['CodeMateriel'])
                ->where('DateReception', '<=', $fields['DateSortie'])
                ->sum('QuantiteRecu');

            // Calculer la quantité totale déjà sortie avant cette mise à jour
            $totalSortieQuantite = Sortie::where('CodeMateriel', $fields['CodeMateriel'])
                ->where('DateSortie', '<=', $fields['DateSortie'])
                ->sum('QuantiteSortant');

            // Vérifier que la quantité demandée après modification n'excède pas le stock disponible
            $newQuantiteSortant = $fields['QuantiteSortant'];
            $remainingStock = $totalReceptionQuantite - ($totalSortieQuantite - $oldQuantiteSortant);

            if ($newQuantiteSortant > $remainingStock) {
                return response()->json(['message' => 'Stock insuffisant, quantité demandée supérieure aux réceptions disponibles.'], 400);
            }

            // Mise à jour du stock du matériel
            $materiel->Quantite += $oldQuantiteSortant; // Ajouter la quantité de sortie précédente au stock
            $materiel->Quantite -= $newQuantiteSortant; // Soustraire la nouvelle quantité demandée
            $materiel->save();

            $sortie->update($fields); // Mise à jour de la sortie
        }

        return response()->json(['message' => 'Sortie mise à jour avec succès', 'sortie' => $sortie], 200);
    }


    public function destroy($BonSortie)
    {
        $sortie = Sortie::where('BonSortie', $BonSortie)->first();

        if ($sortie) {
            if (Gate::allows('delete-sortie', $sortie)) {
                // Récupérer le matériel associé à cette sortie
                $materiel = Materiel::find($sortie->CodeMateriel);

                if ($materiel) {
                    // Ajouter la quantité sortante au stock
                    $materiel->Quantite += $sortie->QuantiteSortant; // Ajouter la quantité sortante au stock du matériel
                    $materiel->save(); // Enregistrer les changements
                }

                // Supprimer la sortie
                $sortie->delete();
                return response()->json(['message' => 'Sortie supprimée avec succès'], 200);
            } else {
                return response()->json(['error' => 'Vous n\'avez pas la permission de supprimer cette sortie'], 403);
            }
        } else {
            return response()->json(['error' => 'Sortie non trouvée'], 404);
        }
    }
}
