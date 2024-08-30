# Stripe Sales Tax Aid

This repository was born out of frustration computing sales tax due for SaaS services in
Texas in 2017 when we didn't have full address records available.

Perhaps some of the scripts or logic here will be useful to others.

I'm not a tax professional, so read through the code, understand what it is doing
and use at your own risk


# Usage:
Download your stripe transaction report and save it to `your-stripe-transaction-export.csv`.

Modify the start and end dates in the script to match the time period you are examining.

Some Stripe data is not very sanitized, you may have to make modifications to the
`$zipToState` or `$fixes` arrays for data in your data set that to not conform.

For example, I found that `ILLINOISE` needed to be corrected to `IL` and several others


## Summary by State
To summarize your sales by state, run salesByState.php.

This works by first determining the number of transactions and total revenue that were sold
to "Known" locations. It uses Stripe's Shipping Address, and Card Address, and currency to
try to determine a location.

Imagine that it is able to determine locations for 40% of your transactions, and that of those,
6% of transactions were in the state of Texas. It calculates an "inferred" number of transactions
and revenue to Texas using the remaining 60% of unknown transactions and muliplying that by
6%. It does this for each US State, as well as "NON-US" so you have a pretty good and defensible
idea of revenue to each state.


## Texas Sales Detail
Run `texasSalesDetail.php` to provide data suitable for completing the Texas "Sales Tax Worksheet"
Excel file.  This script goes into more detail on transaction to try to determine the taxable
jurisdiction, and specific city, county, and special tax rates that may apply.

The output is a CSV file which can be imported into their spreadsheet in call A8.

This script relies on the `texas-city-rates.txt` file, downloaded and converted from
https://comptroller.texas.gov/taxes/sales/city.php as of the date of this commit.

This script works by looking up the zip code from Stripe and determining the city name and matching
it to a city in the file to determing city, county, and special tax rates. It calculates
the sales tax due for each individual transaction. It is currently hard-coded for
"Data Processing Services", which applies to most Software as a Service (SaaS) businesses. That
tax category is taxed at 80% of the collected amount.


## More Information
There may be more information about this on my blog post at
https://www.brandonchecketts.com/archives/sales-tax-fromstripe-transactions-report

