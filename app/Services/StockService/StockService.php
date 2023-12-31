<?php
namespace App\Services\StockService;
use App\Models\Order;
use App\Models\Stock;
use App\Events\LowStockEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Repositories\OrderRepository;
use App\Repositories\StockRepository;
use App\Repositories\ProductRepository;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

class StockService implements StockServiceContract
{

    protected Collection $orderItems;
    
    protected Order $order;
    
    public function __construct(
        protected OrderRepository $orderRepository,
        protected StockRepository $stockRepository
    ) {}

    public function updateStock(int $orderId) : void
    {
        DB::beginTransaction();
        try {
            $this->order = $this->orderRepository->getOne($orderId);
            $this->orderItems = $this->order->items()->get();
            $this->orderItems->map(function($item) {
                $productWithIngredients = $item->product()->with(['ingredients', 'ingredients.stock'])->first();
                foreach ($productWithIngredients->ingredients as $key => $ingred) {
                    $dbStock = $ingred->stock;
                    $quantityUsedInTheProduct = $ingred->pivot->quantity;
                    
                    $stockToUpdate = $this->stockRepository->getOne($dbStock->id);
                    $qtyToBeSave = $stockToUpdate->current_stock - ($item->quantity * $quantityUsedInTheProduct);
                    if ($qtyToBeSave <= 0) {
                        $stockToUpdate->current_stock = 0;
                    } else {
                        $stockToUpdate->current_stock = $qtyToBeSave;
                    }
                    $stockToUpdate->save();

                    // Run low stock event to check it reached to 50% of the stock quantity
                    LowStockEvent::dispatch($stockToUpdate->refresh());
                }
            });
        
            DB::commit();
        } catch (\Throwable $th) {
            DB::rollBack();
            throw new GoneHttpException($th->getMessage());
        }
    }
}