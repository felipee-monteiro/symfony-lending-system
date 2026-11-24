## Expected structure

src/
├─ Loan/
│ ├─ Domain/
│ │ ├─ Model/ (Item, Loan, ItemId, BorrowerId, Period, ItemStatus)
│ │ └─ Port/ (empty in V1)
│ ├─Application/
│ │ └─ LendItem/ (LendItem, LendItemHandler)
│ └─ Infrastructure/
│ ├─ Persistence/ (DoctrineItemRepository, mapping)
│ └─ Http/ (Controllers)